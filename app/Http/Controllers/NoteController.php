<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Note;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Professeur;
use App\Models\Eleve;

class NoteController extends Controller
{
    public function notes(Request $request)
    {
        $user = Auth::user();
        $isProfesseur = $user instanceof \App\Models\Professeur;
        $isDirection = $user instanceof \App\Models\Direction;
        $isAdmin = $user instanceof \App\Models\User;

        // VÃ©rifier que c'est bien un professeur ou une direction
        if (! $isProfesseur && ! $isDirection && ! $isAdmin) {
            return response()->json(['error' => 'Non autorisÃ©'], 403);
        }

        // Charger les classes avec leurs matiÃ¨res enseignÃ©es par ce professeur si c'est un prof
        if ($isProfesseur) {
            $user->load(['classes' => function ($query) use ($user) {
                $query->with(['matieres' => function ($q) use ($user) {
                    $q->wherePivot('professeur_id', $user->id)
                        ->orderBy('pivot_ordre_affichage');
                }])->withCount('eleves');
            }]);
        }

        $matiere = null;
        $classe_selectionnee = null;
        $eleves = collect();
        $notes_existantes = collect();
        
        // Si une classe est sÃ©lectionnÃ©e
        if ($request->has('classe_id')) {
            $classe_selectionnee = Classe::find($request->classe_id);
            
            // DÃ©terminer la matiÃ¨re STRICTEMENT
            if ($request->has('matiere_id')) {
                $matiere = Matiere::find($request->matiere_id);
            }
        }

        // Si tous les paramÃ¨tres sont prÃ©sents
        if ($classe_selectionnee && $matiere && $request->has('trimestre')) {

            // Charger les Ã©lÃ¨ves de la classe par ordre alphabÃ©tique
            $eleves = $classe_selectionnee->eleves()
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();

            // Charger les notes existantes pour ce trimestre
            $notes_existantes = Note::where('classe_id', $request->classe_id)
                ->where('trimestre', $request->trimestre)
                ->where('matiere_id', $matiere->id)
                ->get()
                ->keyBy('eleve_id');
        }

        return response()->json([
            'success' => true,
            'professeur' => $user,
            'classe_selectionnee' => $classe_selectionnee,
            'matiere' => $matiere,
            'eleves' => $eleves,
            'notes_existantes' => $notes_existantes,
        ]);
    }

    public function storeNotes(Request $request)
    {
        $user = Auth::user();
        $isProfesseur = $user instanceof \App\Models\Professeur;
        $isCenseur = ($user instanceof \App\Models\Direction && in_array($user->role, ['censeur', 'directeur'])) || $user instanceof \App\Models\User;

        if (! $isProfesseur && ! $isCenseur) {
            return response()->json(['error' => 'Non autorisÃ©'], 403);
        }

        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'trimestre' => 'required|integer|between:1,3',
            'matiere_id' => 'required|exists:matieres,id',
            'type_note' => 'required|in:interro,devoir',
            'numero' => 'required|integer',
            'notes' => 'required|array',
            'notes.*' => 'nullable|numeric|min:0|max:20',
        ]);

        // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe et matiÃ¨re
        if ($isProfesseur && ! $user->classes->contains($request->classe_id)) {
            return response()->json(['error' => 'Vous n\'Ãªtes pas assignÃ© Ã  cette classe.'], 403);
        }

        // -- Restriction EmploiDuTemps : professeur autorise seulement ses jours de cours
        if ($isProfesseur) {
            $joursSemaine = [1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi',7=>'Dimanche'];
            $nomJour = $joursSemaine[now()->dayOfWeekIso] ?? null;
            $aDuCours = \App\Models\EmploiDuTemps::where('professeur_id', $user->id)
                ->where('classe_id', $request->classe_id)
                ->where('jour', $nomJour)
                ->exists();
            if (!$aDuCours) {
                return response()->json(['error' => "Saisie de notes non autorisee : vous n'avez pas cours dans cette classe le {$nomJour}."], 403);
            }
        }
        // -------------------------------------------------------------------------

        $classe = Classe::findOrFail($request->classe_id);
        $matiere = Matiere::findOrFail($request->matiere_id);

        // RÃ©cupÃ©rer le coefficient depuis la table classe_matiere
        $profId = $isProfesseur ? $user->id : DB::table('classe_matiere')
                                                 ->where('classe_id', $request->classe_id)
                                                 ->where('matiere_id', $request->matiere_id)
                                                 ->value('professeur_id');

        $coefficient = DB::table('classe_matiere')
            ->where('classe_id', $request->classe_id)
            ->where('matiere_id', $request->matiere_id)
            ->where('professeur_id', $profId)
            ->value('coefficient');

        // Si le coefficient n'est pas trouvÃ©, utiliser une valeur par dÃ©faut
        $coefficient = $coefficient ?? 1.0;

        $numero = (int) $request->numero;

        // Validation supplÃ©mentaire du numÃ©ro
        if ($request->type_note === 'interro' && ($numero < 1 || $numero > 4)) {
            return response()->json(['error' => 'NumÃ©ro d\'interrogation invalide.'], 400);
        }

        if ($request->type_note === 'devoir' && ($numero < 1 || $numero > 2)) {
            return response()->json(['error' => 'NumÃ©ro de devoir invalide.'], 400);
        }

        // DÃ©terminer la colonne une seule fois
        $colonne = $request->type_note === 'interro'
            ? match ($numero) {
                1 => 'premier_interro',
                2 => 'deuxieme_interro',
                3 => 'troisieme_interro',
                4 => 'quatrieme_interro',
            }
        : match ($numero) {
            1 => 'premier_devoir',
            2 => 'deuxieme_devoir',
        };

        DB::beginTransaction();

        try {
            $notesEnregistrees = 0;
            $notesIgnorees = 0;

            foreach ($request->notes as $eleveId => $valeurNote) {
                if (! is_null($valeurNote)) {
                    // VÃ©rifier si une note existe dÃ©jÃ  pour cet Ã©lÃ¨ve, cette matiÃ¨re, ce trimestre
                    $existingNote = Note::where('eleve_id', $eleveId)
                        ->where('classe_id', $request->classe_id)
                        ->where('matiere_id', $request->matiere_id)
                        ->where('trimestre', $request->trimestre)
                        ->first();

                    // Si la note existe et que la colonne cible a dÃ©jÃ  une valeur, on refuse la mise Ã  jour
                    if (!$isCenseur && $existingNote && !is_null($existingNote->$colonne)) {
                        $notesIgnorees++;
                        continue; // On ignore cette note pour ne pas Ã©craser l'existant si on n'est pas censeur
                    }

                    $note = Note::updateOrCreate(
                        [
                            'eleve_id' => $eleveId,
                            'classe_id' => $request->classe_id,
                            'matiere_id' => $request->matiere_id,
                            'trimestre' => $request->trimestre,
                        ],
                        [
                            $colonne => $valeurNote,
                            'professeur_id' => $profId, // Associate with the assigned teacher even if entered by Censeur
                            'coefficient' => $coefficient,
                            'is_validated' => $isCenseur, // Censeur updates automatically validate the grade
                            'validated_at' => $isCenseur ? now() : null,
                            'validated_by' => $isCenseur ? 'Censeur' : null,
                        ]
                    );
                    $notesEnregistrees++;

                    // Notifier les parents
                    $tuteurs = \App\Models\Tuteur::whereHas('eleves', function($q) use ($eleveId) {
                        $q->where('eleves.id', $eleveId);
                    })->get();
                    
                    foreach ($tuteurs as $tuteur) {
                        $tuteur->notify(new \App\Notifications\NoteAddedNotification($note));
                    }

                    // --- ALGORITHME D'ALERTE CHUTE DE NOTES ---
                    $declencherAlerte = false;
                    $raisonAlerte = '';

                    // VÃ©rification : 2 mauvaises notes consÃ©cutives
                    if ($valeurNote < 10) {
                        if ($request->type_note === 'interro') {
                            if ($numero == 2 && $note->premier_interro !== null && $note->premier_interro < 10) {
                                $declencherAlerte = true;
                                $raisonAlerte = 'a obtenu consÃ©cutivement moins de la moyenne de classe aux interrogations';
                            } elseif ($numero == 3 && $note->deuxieme_interro !== null && $note->deuxieme_interro < 10) {
                                $declencherAlerte = true;
                                $raisonAlerte = 'a obtenu consÃ©cutivement moins de la moyenne de classe aux interrogations';
                            } elseif ($numero == 4 && $note->troisieme_interro !== null && $note->troisieme_interro < 10) {
                                $declencherAlerte = true;
                                $raisonAlerte = 'a obtenu consÃ©cutivement moins de la moyenne de classe aux interrogations';
                            }
                        } elseif ($request->type_note === 'devoir') {
                            if ($numero == 2 && $note->premier_devoir !== null && $note->premier_devoir < 10) {
                                $declencherAlerte = true;
                                $raisonAlerte = 'a Ã©chouÃ© consÃ©cutivement aux devoirs sur table';
                            }
                        }
                    }

                    // DÃ©clenchement
                    $note->loadMissing(['eleve', 'matiere']);
                    $eleveAlerte = $note->eleve;
                    $matiereAlerte = $note->matiere;
                    $isNewOrUpdated = $note->wasRecentlyCreated || $note->wasChanged($colonne);

                    if ($declencherAlerte) {
                        try {
                            // Push Parent
                            foreach ($tuteurs as $tuteur) {
                                $tuteur->notify(new \App\Notifications\AlerteChuteNotesNotification($eleveAlerte, $matiereAlerte, $raisonAlerte));
                            }

                            // Push Professeur via Professeur Token
                            $profToAlert = \App\Models\Professeur::find($profId);
                            if ($profToAlert) {
                                $profToAlert->notify(new \App\Notifications\AlerteChuteNotesNotification($eleveAlerte, $matiereAlerte, $raisonAlerte));
                            }

                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Erreur Push Alerte Notes : ' . $e->getMessage());
                        }
                    }

                    // --- ENVOI WHATSAPP AUTOMATIQUE (POUR TOUTE NOUVELLE NOTE OU CHUTE) ---
                    if ($isNewOrUpdated) {
                        $typeEval = $request->type_note === 'interro' ? 'Interrogation' : 'Devoir';
                        $messageType = "$typeEval $numero";
                        
                        $texteWhatsapp = "ðŸ“ *Nouvelle Note AjoutÃ©e*\n\n";
                        $texteWhatsapp .= "Ã‰lÃ¨ve : *{$eleveAlerte->nom_complet}*\n";
                        $texteWhatsapp .= "MatiÃ¨re : *{$matiereAlerte->nom}*\n";
                        $texteWhatsapp .= "Ã‰valuation : *$messageType*\n";
                        $texteWhatsapp .= "Note : *$valeurNote/20*\n\n";

                        if ($declencherAlerte) {
                            $texteWhatsapp .= "âš ï¸ *ATTENTION* : L'Ã©lÃ¨ve $raisonAlerte. Merci de suivre cela de prÃ¨s de votre cÃ´tÃ©.\n\n";
                        }

                        $texteWhatsapp .= "Connectez-vous Ã  l'espace parent pour plus de dÃ©tails.";

                        // Envoi au rÃ©pÃ©titeur
                        if (!empty($eleveAlerte->repetiteur_whatsapp)) {
                            try {
                                \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production-b710.up.railway.app') . '/send', [
                                    'phone' => $eleveAlerte->repetiteur_whatsapp,
                                    'message' => $texteWhatsapp
                                ]);
                            } catch (\Exception $reqEx) {
                                \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Repetiteur) : ' . $reqEx->getMessage());
                            }
                        }

                        // Envoi aux parents (Tuteurs)
                        foreach ($tuteurs as $tuteur) {
                            if (!empty($tuteur->telephone)) {
                                try {
                                    \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production-b710.up.railway.app') . '/send', [
                                        'phone' => $tuteur->telephone,
                                        'message' => $texteWhatsapp
                                    ]);
                                } catch (\Exception $reqEx) {
                                    \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Parent) : ' . $reqEx->getMessage());
                                }
                            }
                        }
                    }
                }
            }

            DB::commit();

            $message = 'Notes enregistrÃ©es avec succÃ¨s!';
            if ($notesIgnorees > 0) {
                $message .= " ($notesIgnorees note(s) ignorÃ©e(s) car dÃ©jÃ  existantes)";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'details' => [
                    'enregistrees' => $notesEnregistrees,
                    'ignorees' => $notesIgnorees
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur enregistrement notes: '.$e->getMessage());

            return response()->json(['error' => 'Erreur lors de l\'enregistrement des notes: '.$e->getMessage()], 500);
        }
    }

    public function calculerMoyennes(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Non authentifiÃ©'], 401);
        }

        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'trimestre' => 'required|integer|between:1,3',
            'matiere_id' => 'required|exists:matieres,id',
        ]);

        $professeur = Auth::user();

        // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe et matiÃ¨re
        if (! $professeur->classes->contains($request->classe_id)) {
            return response()->json(['error' => 'Vous n\'Ãªtes pas assignÃ© Ã  cette classe.'], 403);
        }

        // RÃ©cupÃ©rer la classe et la matiÃ¨re
        $classe_selectionnee = Classe::find($request->classe_id);
        $matiere = Matiere::find($request->matiere_id);

        // VÃ©rifier que la classe et la matiÃ¨re existent
        if (! $classe_selectionnee || ! $matiere) {
            return response()->json(['error' => 'Classe ou matiÃ¨re non trouvÃ©e.'], 404);
        }

        // RÃ©cupÃ©rer le coefficient depuis la table classe_matiere
        $coefficient = DB::table('classe_matiere')
            ->where('classe_id', $request->classe_id)
            ->where('matiere_id', $request->matiere_id)
            ->where('professeur_id', $professeur->id)
            ->value('coefficient');

        $coefficient = $coefficient ?? 1.0;

        // RÃ©cupÃ©rer toutes les notes de la classe pour le trimestre
        $notes = Note::where('classe_id', $request->classe_id)
            ->where('matiere_id', $request->matiere_id)
            ->where('trimestre', $request->trimestre)
            ->with('eleve')
            ->get();

        // Mettre Ã  jour les coefficients si nÃ©cessaire
        foreach ($notes as $note) {
            if ($note->coefficient != $coefficient) {
                $note->coefficient = $coefficient;
                $note->save();
            }
        }

        // Recharger les notes aprÃ¨s mise Ã  jour
        $notes = Note::where('classe_id', $request->classe_id)
            ->where('matiere_id', $request->matiere_id)
            ->where('trimestre', $request->trimestre)
            ->with('eleve')
            ->get();

        // Calculer les moyennes et classer les Ã©lÃ¨ves
        $moyennes = $notes->map(function ($note) use ($coefficient) {
            return [
                'eleve_id' => $note->eleve_id,
                'eleve_nom' => $note->eleve->nom,
                'eleve_prenom' => $note->eleve->prenom,
                'premier_interro' => $note->premier_interro,
                'deuxieme_interro' => $note->deuxieme_interro,
                'troisieme_interro' => $note->troisieme_interro,
                'quatrieme_interro' => $note->quatrieme_interro,
                'moyenne_interro' => $note->moyenne_interro,
                'premier_devoir' => $note->premier_devoir,
                'deuxieme_devoir' => $note->deuxieme_devoir,
                'moyenne_trimestrielle' => $note->moyenne_trimestrielle,
                'coefficient' => $coefficient,
                'moyenne_coefficientee' => $note->moyenne_trimestrielle ? $note->moyenne_trimestrielle * $coefficient : null,
                'commentaire' => $note->commentaire,
            ];
        })->sortByDesc('moyenne_trimestre');

        // Ajouter le rang
        $moyennesAvecRang = $moyennes->values()->map(function ($item, $index) {
            $item['rang'] = $index + 1;

            return $item;
        });

        // RÃ©cupÃ©rer Ã©galement les autres variables nÃ©cessaires pour la vue
        $professeur->load(['classes' => function ($query) use ($professeur) {
            $query->with(['matieres' => function ($q) use ($professeur) {
                $q->wherePivot('professeur_id', $professeur->id)
                    ->orderBy('pivot_ordre_affichage');
            }])->withCount('eleves');
        }]);

        $eleves = collect();
        $notes_existantes = collect();

        return response()->json([
            'success' => true,
            'professeur' => $professeur,
            'moyennesAvecRang' => $moyennesAvecRang,
            'classe_selectionnee' => $classe_selectionnee,
            'matiere' => $matiere,
            'eleves' => $eleves,
            'notes_existantes' => $notes_existantes,
            'filters' => [
                'classe_id' => $request->classe_id,
                'trimestre' => $request->trimestre,
                'matiere_id' => $request->matiere_id,
            ],
        ]);
    }

    public function getMoyennesAjax(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Non authentifiÃ©'], 401);
        }

        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'trimestre' => 'required|integer|between:1,3',
        ]);

        $professeur = Auth::user();

        // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe
        if (! $professeur->classes->contains($request->classe_id)) {
            return response()->json(['error' => 'AccÃ¨s non autorisÃ© Ã  cette classe'], 403);
        }

        // RÃ©cupÃ©rer la matiÃ¨re enseignÃ©e par ce professeur dans cette classe
        $matiere = DB::table('classe_matiere')
            ->where('classe_id', $request->classe_id)
            ->where('professeur_id', $professeur->id)
            ->first();

        if (! $matiere) {
            return response()->json(['error' => 'Aucune matiÃ¨re trouvÃ©e pour cette classe'], 404);
        }

        // RÃ©cupÃ©rer les notes avec les informations des Ã©lÃ¨ves
        $notes = Note::with('eleve')
            ->where('classe_id', $request->classe_id)
            ->where('trimestre', $request->trimestre)
            ->where('matiere_id', $matiere->matiere_id)
            ->where('professeur_id', $professeur->id)
            ->get();

        if ($notes->isEmpty()) {
            return response()->json(['moyennes' => []]);
        }

        // Calculer le rang pour chaque Ã©lÃ¨ve
        $notesAvecRang = $notes->map(function ($note) use ($notes) {
            // Calculer le rang en fonction de la moyenne coefficientÃ©e
            $rang = $notes->where('moyenne_coefficientee', '>', $note->moyenne_coefficientee)
                ->count() + 1;

            return [
                'rang' => $rang,
                'eleve_nom' => $note->eleve->nom,
                'eleve_prenom' => $note->eleve->prenom,
                'premier_interro' => $note->premier_interro,
                'deuxieme_interro' => $note->deuxieme_interro,
                'troisieme_interro' => $note->troisieme_interro,
                'quatrieme_interro' => $note->quatrieme_interro,
                'moyenne_interro' => $note->moyenne_interro,
                'premier_devoir' => $note->premier_devoir,
                'deuxieme_devoir' => $note->deuxieme_devoir,
                'moyenne_trimestrielle' => $note->moyenne_trimestrielle,
                'coefficient' => $note->coefficient,
                'moyenne_coefficientee' => $note->moyenne_coefficientee,
                'commentaire' => $note->commentaire,
            ];
        })->sortBy('rang')->values();

        return response()->json([
            'moyennes' => $notesAvecRang,
        ]);
    }

    public function getMoyennesForDashboard($classeId, $trimestre, $professeurId)
    {
        try {
            // RÃ©cupÃ©rer la matiÃ¨re enseignÃ©e par ce professeur dans cette classe
            $matiere = DB::table('classe_matiere')
                ->where('classe_id', $classeId)
                ->where('professeur_id', $professeurId)
                ->first();

            if (! $matiere) {
                return [];
            }

            // RÃ©cupÃ©rer les notes avec les informations des Ã©lÃ¨ves
            $notes = Note::with('eleve')
                ->where('classe_id', $classeId)
                ->where('trimestre', $trimestre)
                ->where('matiere_id', $matiere->matiere_id)
                ->where('professeur_id', $professeurId)
                ->get();

            if ($notes->isEmpty()) {
                return [];
            }

            // Calculer le rang pour chaque Ã©lÃ¨ve
            $notesAvecRang = $notes->map(function ($note) use ($notes, $classeId) {
                // Calculer le rang en fonction de la moyenne coefficientÃ©e
                $rang = $notes->where('moyenne_coefficientee', '>', $note->moyenne_coefficientee)
                    ->count() + 1;

                // RÃ©cupÃ©rer le nom de la classe
                $classe = Classe::find($classeId);

                return [
                    'rang' => $rang,
                    'eleve_nom' => $note->eleve->nom,
                    'eleve_prenom' => $note->eleve->prenom,
                    'premier_interro' => $note->premier_interro,
                    'deuxieme_interro' => $note->deuxieme_interro,
                    'troisieme_interro' => $note->troisieme_interro,
                    'quatrieme_interro' => $note->quatrieme_interro,
                    'moyenne_interro' => $note->moyenne_interro,
                    'premier_devoir' => $note->premier_devoir,
                    'deuxieme_devoir' => $note->deuxieme_devoir,
                    'moyenne_trimestrielle' => $note->moyenne_trimestrielle,
                    'coefficient' => $note->coefficient,
                    'moyenne_coefficientee' => $note->moyenne_coefficientee,
                    'commentaire' => $note->commentaire,
                    'classe_nom' => $classe ? $classe->nom : 'Classe inconnue',
                ];
            })->sortBy('rang')->values()->toArray();

            return $notesAvecRang;

        } catch (\Exception $e) {
            Log::error('Erreur rÃ©cupÃ©ration moyennes dashboard: '.$e->getMessage());

            return [];
        }
    }

    public function dashboard(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Non authentifiÃ©'], 401);
        }

        $professeur = Auth::user();

        // Charger les classes avec le nombre d'Ã©lÃ¨ves
        $professeur->load(['classes' => function ($query) {
            $query->withCount('eleves');
        }]);

        // RÃ©cupÃ©rer toutes les matiÃ¨res disponibles
        $matieres = Matiere::orderBy('nom')->get();

        $stats = [
            'classes_count' => $professeur->classes->count(),
            'eleves_count' => $professeur->classes->sum('eleves_count'),
            'cours_semaine' => 8,
        ];

        // RÃ©cupÃ©rer les moyennes si des filtres sont appliquÃ©s
        $moyennesData = [];
        if ($request->has('classe_id') && $request->has('trimestre')) {
            $moyennesData = $this->getMoyennesForDashboard(
                $request->classe_id,
                $request->trimestre,
                $professeur->id
            );
        }

        return response()->json([
            'success' => true,
            'professeur' => $professeur,
            'stats' => $stats,
            'matieres' => $matieres,
            'moyennesData' => $moyennesData,
        ]);
    }
}

