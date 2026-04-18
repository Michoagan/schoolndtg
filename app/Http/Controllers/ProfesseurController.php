<?php

namespace App\Http\Controllers;

use App\Models\CahierTexte;
use App\Models\Classe;
use App\Models\Direction;
use App\Models\Eleve;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\PasswordResetCode;
use App\Models\Professeur;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\ProfessorAccountCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProfesseurController extends Controller
{
    // public function create() removed

    public function store(Request $request)
    {
        $validated = $request->validate([
            'last_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'gender' => 'required|in:M,F',
            'birth_date' => 'required|date|before:-18 years',
            'email' => 'required|email|unique:professeurs,email',
            'phone' => 'required|string|max:20',
            'matiere_id' => 'required|exists:matieres,id',
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $personalCode = strtoupper(substr($validated['last_name'], 0, 5)).rand(1000, 9999);

        // Gérer l'upload de la photo
        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $photoName = 'prof_'.time().'_'.Str::slug($validated['last_name']).'.'.$photo->getClientOriginalExtension();

            // Stocker l'image dans storage/app/public/professeurs
            $photoPath = $photo->storeAs('professeurs', $photoName, 'public');
        }

        // Créer le professeur
        $professeur = Professeur::create([
            'last_name' => $validated['last_name'],
            'first_name' => $validated['first_name'],
            'gender' => $validated['gender'],
            'birth_date' => $validated['birth_date'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'matiere_id' => $validated['matiere_id'],
            'matiere' => 'Voir relation', // Champ legacy
            'photo' => $photoName, // Stocker seulement le nom du fichier
            'personal_code' => Hash::make($personalCode), // Stocker le hash en base
        ]);

        // Attacher les matières (Legacy support if needed, but we are moving to 1-1)
        // $professeur->matieres()->attach($validated['matieres']);

        // Envoyer la notification avec le code personnel EN CLAIR
        $professeur->notify(new ProfessorAccountCreatedNotification($professeur, $personalCode));

        // Réponse JSON au lieu de redirect
        return response()->json([
            'success' => true,
            'message' => 'Professeur inscrit avec succès! Un email avec le code personnel a été envoyé.',
            'data' => $professeur,
        ], 201);
    }

    /**
     * Afficher la liste des professeurs
     */
    // Dans votre méthode index() ou show() du contrôleur
    public function index()
    {
        try {
            // Get all professors
            $professeurs = Professeur::with('matiere')->orderBy('last_name')->orderBy('first_name')->get();

            return response()->json([
                'success' => true,
                'professeurs' => $professeurs,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des professeurs: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des professeurs.',
            ], 500);
        }
    }

    /**
     * Afficher le formulaire de modification
     */
    // public function edit(Professeur $professeur) removed

    /**
     * Mettre à jour un professeur
     */

    /**
     * Supprimer un professeur
     */
    public function destroy(Professeur $professeur)
    {
        try {
            // Supprimer la photo
            if ($professeur->photo) {
                Storage::disk('public')->delete('professeurs/'.$professeur->photo);
            }

            $professeur->delete();

            return response()->json([
                'success' => true,
                'message' => 'Professeur supprimé avec succès!',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur suppression professeur: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: '.$e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, Professeur $professeur)
    {
        $validator = Validator::make($request->all(), [
            'last_name' => 'required|string|max:255',
            'first_name' => 'required|string|max:255',
            'gender' => 'required|in:M,F',
            'birth_date' => 'required|date|before:-18 years',
            'email' => 'required|email|unique:professeurs,email,'.$professeur->id,
            'phone' => 'required|string|max:20',
            'matiere_id' => 'required|exists:matieres,id',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Handle photo upload
            if ($request->hasFile('photo')) {
                // Delete old photo
                if ($professeur->photo) {
                    Storage::disk('public')->delete('professeurs/'.$professeur->photo);
                }

                $photo = $request->file('photo');
                $photoName = 'prof_'.time().'_'.Str::slug($request->last_name).'.'.$photo->getClientOriginalExtension();
                $photoPath = $photo->storeAs('professeurs', $photoName, 'public');
                $professeur->photo = $photoName;
            }

            $professeur->update($request->except(['photo', 'personal_code']));

            return response()->json([
                'success' => true,
                'message' => 'Professeur modifié avec succès',
                'data' => $professeur,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la modification du professeur: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la modification.',
            ], 500);
        }
    }
    // showLoginForm() removed

    public function login(Request $request)
    {
        // Removed session check for API

        $request->validate([
            'email' => 'required|email',
            'personal_code' => 'required|string',
        ]);

        $credentials = $request->only('email', 'personal_code');

        // Vérifier si le professeur existe avec cet email
        $professeur = Professeur::where('email', $credentials['email'])->first();

        if (! $professeur) {
            return back()->withErrors([
                'email' => 'Aucun professeur trouvé avec cet email.',
            ])->withInput();
        }

        // Vérifier le code personnel
        // Vérifier le code personnel
        if (! Hash::check($credentials['personal_code'], $professeur->personal_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Code personnel incorrect.',
            ], 401);
        }

        // Créer un token Sanctum
        $token = $professeur->createToken('professeur_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $professeur,
        ]);
    }

    public function logout()
    {
        // Révoquer le token actuel
        if (Auth::guard('sanctum')->check()) {
            /** @var \Laravel\Sanctum\PersonalAccessToken $token */
            $token = Auth::guard('sanctum')->user()->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
             ]);
    }

    /**
     * Update FCM Token for Push Notifications
     */
    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $professeur = Auth::user();
        $professeur->fcm_token = $request->fcm_token;
        $professeur->save();

        return response()->json([
            'success' => true,
            'message' => 'FCM Token updated successfully.',
        ]);
    }

    /**
     * Signaler un exercice non fait pour un élève
     */
    public function signalerExerciceNonFait(Request $request)
    {
        $request->validate([
            'eleve_id' => 'required|exists:eleves,id',
        ]);

        $professeur = Auth::user();

        if (!$professeur instanceof \App\Models\Professeur) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            $eleve = \App\Models\Eleve::findOrFail($request->eleve_id);
            
            // Récupérer les tuteurs de l'élève
            $tuteurs = $eleve->tuteurs;

            if ($tuteurs->isNotEmpty()) {
                // Envoyer la notification
                \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\ExerciceNonFaitNotification($eleve));
            }

            // --- ENVOI WHATSAPP AUTOMATIQUE AU REPETITEUR ---
            if (!empty($eleve->repetiteur_whatsapp)) {
                $texteWhatsapp = "⚠️ *Alerte Exercice Non Fait*\n\n";
                $texteWhatsapp .= "Élève : *{$eleve->nom_complet}*\n";
                $texteWhatsapp .= "Le professeur *{$professeur->nom} {$professeur->prenom}* signale que votre enfant n'a pas fait son exercice aujourd'hui.\n\n";
                $texteWhatsapp .= "Merci de suivre cela de près.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(3)->post(env('WHATSAPP_BOT_URL', 'http://localhost:3000') . '/send', [
                        'phone' => $eleve->repetiteur_whatsapp,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp : ' . $reqEx->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'L\'exercice non fait a été signalé aux parents.',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors du signalement d\'exercice non fait: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du signalement.',
            ], 500);
        }
    }

    /**
     * Changer le code personnel (Authentifié)
     */
    public function changeCode(Request $request)
    {
        $request->validate([
            'current_code' => 'required',
            'new_code' => 'required|string|min:6',
        ]);

        $professeur = Auth::user();

        if (! Hash::check($request->current_code, $professeur->personal_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Le code actuel est incorrect.',
            ], 400);
        }

        $professeur->personal_code = Hash::make($request->new_code);
        $professeur->save();

        return response()->json([
            'success' => true,
            'message' => 'Code personnel modifié avec succès.',
        ]);
    }

    /**
     * Demande de réinitialisation de code (Public)
     */
    public function forgotCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $professeur = Professeur::where('email', $request->email)->first();

        if (! $professeur) {
            // Pour sécurité, on dit quand même envoyé
            return response()->json([
                'success' => true,
                'message' => 'Si cet email existe, un code de réinitialisation a été envoyé.',
            ]);
        }

        // Générer un code à 6 chiffres
        $code = rand(100000, 999999);

        // Stocker dans password_reset_codes (table commune ou nouvelle)
        PasswordResetCode::updateOrCreate(
            ['email' => $request->email],
            ['code' => $code, 'created_at' => now()]
        );

        // Envoyer notification (Simulé ici, à implémenter avec Notification class)
        // $professeur->notify(new ResetCodeNotification($code));
        // Pour le hackathon/démo: log le code ou utiliser une méthode simple
        // TODO: Créer la notification réelle
        
        // TEMPORAIRE: Log pour démo sans mailer configuré
        Log::info("RESET CODE pour {$professeur->email}: $code");

        return response()->json([
            'success' => true,
            'message' => 'Un code de réinitialisation a été envoyé à votre email.',
            'debug_code' => $code, // A RETIRER EN PROD
        ]);
    }

    /**
     * Réinitialiser le code avec le token (Public)
     */
    public function resetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|numeric',
            'new_personal_code' => 'required|string|min:6',
        ]);

        $resetEntry = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (! $resetEntry || $resetEntry->created_at->addMinutes(15)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Code invalide ou expiré.',
            ], 400);
        }

        $professeur = Professeur::where('email', $request->email)->firstOrFail();
        $professeur->personal_code = Hash::make($request->new_personal_code);
        $professeur->save();

        // Supprimer le code utilisé
        $resetEntry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Votre code personnel a été réinitialisé avec succès. Vous pouvez vous connecter.',
        ]);
    }

    public function dashboard(Request $request)
    {
        try {
            // Auth via Sanctum
            $professeur = $request->user();
            // ...

            // Charger les classes avec les élèves ET la matière
            $professeur->load(['matiere', 'classes.eleves' => function ($query) {
                $query->orderBy('nom')->orderBy('prenom');
            }]);

            // Récupérer les statistiques
            $stats = [
                'classes_count' => $professeur->classes->count(),
                'eleves_count' => $professeur->classes->sum(function ($classe) {
                    return $classe->eleves->count();
                }),
                'cours_semaine' => \App\Models\EmploiDuTemps::where('professeur_id', $professeur->id)->count(),
            ];

            // Récupérer les communiqués récents (Général ou Professeurs)
            $communiques = \App\Models\Communique::whereIn('type', ['general', 'professeurs'])
                ->where('is_published', true)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // Récupérer les événements à venir
            $evenements = \App\Models\Evenement::where('date_fin', '>=', now())
                ->orderBy('date_debut', 'asc')
                ->take(5)
                ->get();

            return response()->json([
                'success' => true,
                'professeur' => $professeur,
                'stats' => $stats,
                'communiques' => $communiques,
                'evenements' => $evenements,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function emploiDuTemps()
    {
        try {
            $professeur = Auth::user();

            if (! $professeur instanceof Professeur) {
                return response()->json(['error' => 'Non autorisé'], 403);
            }

            // Récupérer l'emploi du temps avec relations
            $emploisDuTemps = \App\Models\EmploiDuTemps::with(['classe:id,nom', 'matiere:id,nom'])
                ->where('professeur_id', $professeur->id)
                ->orderByRaw("CASE jour 
                    WHEN 'Lundi' THEN 1 
                    WHEN 'Mardi' THEN 2 
                    WHEN 'Mercredi' THEN 3 
                    WHEN 'Jeudi' THEN 4 
                    WHEN 'Vendredi' THEN 5 
                    WHEN 'Samedi' THEN 6 
                    ELSE 7 END")
                ->orderBy('heure_debut', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'emplois_du_temps' => $emploisDuTemps
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur getEmploiDuTemps Professeur: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération de l\'emploi du temps.',
            ], 500);
        }
    }

    public function mesPaiements()
    {
        try {
            $professeur = Auth::user();

            if (! $professeur instanceof Professeur) {
                return response()->json(['error' => 'Non autorisé'], 403);
            }

            // Récupérer les paiements générés par la comptabilité
            $paiements = \App\Models\PaiementProfesseur::where('professeur_id', $professeur->id)
                ->orderBy('annee', 'desc')
                ->orderBy('mois', 'desc')
                ->get();

            // Calculer les heures non payées du mois en cours
            $moisActuel = date('n');
            $anneeActuelle = date('Y');

            $heuresEffectuees = \App\Models\CahierTexte::where('professeur_id', $professeur->id)
                ->whereMonth('date_cours', $moisActuel)
                ->whereYear('date_cours', $anneeActuelle)
                ->whereNull('paiement_id') // S'assurer qu'elles n'ont pas encore été rattachées à un paiement
                ->get();

            $totalHeuresVol = 0;
            $montantEstime = 0;
            
            $heuresParClasse = $heuresEffectuees->groupBy('classe_id');
            $tauxConfigures = \App\Models\TauxHoraire::where('professeur_id', $professeur->id)->get();

            foreach($heuresParClasse as $classeId => $coursList) {
                $heures = $coursList->sum('duree_cours');
                $totalHeuresVol += $heures;

                $tauxSpecifique = $tauxConfigures->firstWhere('classe_id', $classeId);
                $tauxGlobal = $tauxConfigures->firstWhere('classe_id', null);
                $tauxApplique = $tauxSpecifique ? $tauxSpecifique->taux_horaire : ($tauxGlobal ? $tauxGlobal->taux_horaire : 0);
                
                $montantEstime += ($heures * $tauxApplique);
            }

            // Ajouter les primes fixes à l'estimation
            foreach($tauxConfigures as $tc) {
                $montantEstime += $tc->prime_mensuelle;
            }

            return response()->json([
                'success' => true,
                'paiements' => $paiements,
                'heures_non_payees' => [
                    'total_heures' => $totalHeuresVol,
                    'montant_estime' => $montantEstime
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur mesPaiements: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paiements.',
            ], 500);
        }
    }

    public function matieresParClasse($classeId)
    {
        $professeur = Auth::user();

        // Vérifier que c'est bien un professeur
        if (! $professeur instanceof Professeur) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        // ...

        // Vérifier que le professeur a accès à cette classe
        if (! $professeur->classes->contains($classeId)) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        $classe = Classe::with(['matieres' => function ($query) {
            $query->orderBy('pivot_ordre_affichage');
        }])->findOrFail($classeId);

        return response()->json([
            'matieres' => $classe->matieres,
        ]);
    }

    // Dans la classe ProfesseurController

    public function analyseNotes(Request $request)
    {
        $professeur = Auth::user();

        if (! $professeur instanceof Professeur) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $anneeActive = $request->query('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());

        // Charger les classes 
        $professeur->load(['classes' => function ($query) {
            $query->orderBy('nom');
        }]);

        $eleve_selectionne = null;
        $analyse_data = null;
        $classe_selectionnee = null;
        $matiere_selectionnee = null;
        $eleves = collect();

        // 1. Si une classe est sélectionnée (filtrage des élèves)
        if ($request->has('classe_id')) {
            $classe_selectionnee = $professeur->classes->firstWhere('id', $request->classe_id);

            if ($classe_selectionnee) {
                // Charger les élèves de cette classe
                $eleves = Eleve::where('classe_id', $classe_selectionnee->id)
                    ->orderBy('nom')
                    ->orderBy('prenom')
                    ->get();
                
                // Déduction stricte de la matière (cohérent avec getPresences)
                $matiere_selectionnee_id = $professeur->classes()
                    ->where('classe_id', $classe_selectionnee->id)
                    ->first()
                    ->pivot
                    ->matiere_id ?? $professeur->matiere_id;
                    
                if ($matiere_selectionnee_id) {
                     $matiere_selectionnee = \App\Models\Matiere::find($matiere_selectionnee_id);
                }
            }
        }

        // 2. Si un élève est sélectionné -> Lancer l'analyse
        // 2. Si un élève est sélectionné -> Lancer l'analyse élève
        if ($classe_selectionnee && $matiere_selectionnee) {
            $type_analyse = $request->input('type', 'all');
            if ($request->has('eleve_id') && $request->eleve_id) {
                // Analyse individuelle
                $eleve_selectionne = Eleve::find($request->eleve_id);
                if ($eleve_selectionne) {
                    $analyse_data = $this->getAnalyseNotesEleve(
                        $eleve_selectionne->id,
                        $classe_selectionnee->id,
                        $matiere_selectionnee->id,
                        $type_analyse,
                        $anneeActive
                    );
                }
            } else {
                // Analyse globale de la classe (Moyennes par trimestre)
                $analyse_data = $this->getAnalyseNotesClasse(
                        $classe_selectionnee->id,
                        $matiere_selectionnee->id,
                        $anneeActive
                    );
            }
        }

        $notes_examens = collect();
        if ($classe_selectionnee) {
            $query = \App\Models\NoteExamen::where('classe_id', $classe_selectionnee->id)
                ->where('annee_scolaire', $anneeActive);
            if ($matiere_selectionnee) {
                $query->where('matiere_id', $matiere_selectionnee->id);
            }
            if ($eleve_selectionne) {
                $query->where('eleve_id', $eleve_selectionne->id);
            }
            $notes_examens = $query->with(['eleve', 'matiere'])->get()->map(function($n) {
                return [
                    'eleve_nom' => $n->eleve ? $n->eleve->nom_complet : 'Inconnu',
                    'matiere' => $n->matiere ? $n->matiere->nom : 'Inconnue',
                    'type_examen' => $n->type_examen,
                    'valeur' => $n->valeur,
                    'annee_scolaire' => $n->annee_scolaire
                ];
            });
        }

        return response()->json([
            'success' => true,
            'professeur' => $professeur,
            'eleve_selectionne' => $eleve_selectionne,
            'analyse_data' => $analyse_data,
            'classe_selectionnee' => $classe_selectionnee,
            'matiere_selectionnee' => $matiere_selectionnee,
            'eleves' => $eleves,
            'notes_examens' => $notes_examens,
            'annee_scolaire_active' => $anneeActive
        ]);
    }

    private function getAnalyseNotesEleve($eleveId, $classeId, $matiereId, $type = 'all', $anneeScolaire = null)
    {
        if (!$anneeScolaire) $anneeScolaire = \App\Models\Setting::getCurrentAnneeScolaire();
        try {
            // Récupérer les notes de l'élève
            $notesEleve = Note::where('eleve_id', $eleveId)
                ->where('classe_id', $classeId)
                ->where('matiere_id', $matiereId)
                ->where('annee_scolaire', $anneeScolaire)
                ->orderBy('trimestre')
                ->get()
                ->keyBy('trimestre');

            if ($notesEleve->isEmpty()) {
                return null;
            }

            // Récupérer toutes les notes de la classe pour calculer les moyennes par devoir/interro
            $notesClasse = Note::where('classe_id', $classeId)
                ->where('matiere_id', $matiereId)
                ->where('annee_scolaire', $anneeScolaire)
                ->get()
                ->groupBy('trimestre');

            $labels = [];
            $dataEleve = [];
            $dataClasse = [];
            $statistiquesNotes = [];

            // Définition de la structure des évaluations
            $evaluations_all = [
                'premier_interro' => 'I1',
                'deuxieme_interro' => 'I2',
                'troisieme_interro' => 'I3',
                'quatrieme_interro' => 'I4',
                'premier_devoir' => 'D1',
                'deuxieme_devoir' => 'D2',
                'moyenne_trimestrielle' => 'Moy'
            ];

            $evaluations = [];
            if ($type === 'interro') {
                $evaluations = [
                    'premier_interro' => 'I1',
                    'deuxieme_interro' => 'I2',
                    'troisieme_interro' => 'I3',
                    'quatrieme_interro' => 'I4',
                ];
            } elseif ($type === 'devoir') {
                $evaluations = [
                    'premier_devoir' => 'D1',
                    'deuxieme_devoir' => 'D2',
                ];
            } elseif ($type === 'trimestrielle' || $type === 'generale') {
                $evaluations = [
                    'moyenne_trimestrielle' => 'Moy'
                ];
            } else {
                $evaluations = $evaluations_all;
            }

            foreach ([1, 2, 3] as $trimestre) {
                $noteEleve = $notesEleve->get($trimestre);
                $notesDuTrimestre = $notesClasse->get($trimestre);

                // Si pas de données pour ce trimestre (ni élève, ni classe), on saute
                if (! $noteEleve && (! $notesDuTrimestre || $notesDuTrimestre->isEmpty())) {
                    continue;
                }

                foreach ($evaluations as $champ => $labelCourt) {
                    $valeurEleve = $noteEleve ? $noteEleve->$champ : null;
                    
                    // Calcul moyenne classe pour ce champ spécifique
                    $moyenneClasse = 0;
                    if ($notesDuTrimestre && $notesDuTrimestre->isNotEmpty()) {
                        $avg = $notesDuTrimestre->avg($champ);
                        $moyenneClasse = $avg ? round($avg, 2) : 0;
                    }

                    // On ajoute le point si l'élève a une note OU si la classe a une moyenne (pour montrer les 'zéros' ou manques)
                    // Mais pour ne pas surcharger, on peut filtrer : 
                    // Afficher si valeurEleve existe OU (valeurEleve est null mais moyenneClasse > 0, ce qui implique un 0 ou une absence)
                    // Pour l'instant, on affiche tout ce qui est pertinent.
                    if ($valeurEleve !== null || $moyenneClasse > 0) {
                        $labels[] = "T$trimestre $labelCourt";
                        $val = $valeurEleve ?? 0; // Si null mais classe a une note, c'est 0 pour l'élève graphiquement
                        $dataEleve[] = floatval($val); 
                        $dataClasse[] = floatval($moyenneClasse);

                        if ($valeurEleve !== null) {
                            $statistiquesNotes[] = $valeurEleve;
                        }
                    }
                }
            }

            // Calculer les statistiques globales
            $stats = [];
            if (! empty($statistiquesNotes)) {
                $stats = [
                    'moyenne_generale' => round(array_sum($statistiquesNotes) / count($statistiquesNotes), 2),
                    'meilleure_note' => max($statistiquesNotes),
                    'pire_note' => min($statistiquesNotes),
                    'nombre_notes' => count($statistiquesNotes),
                    'tendance' => $this->calculerTendance($dataEleve),
                ];
            } else {
                 // Fallback stats minimales
                $stats = [
                    'moyenne_generale' => 0,
                    'meilleure_note' => 0,
                    'pire_note' => 0,
                    'nombre_notes' => 0,
                    'tendance' => 'stable',
                ];
            }

            // Générer les recommandations
            // On reconstruit une structure compatible avec genererRecommandations ou on adapte
            $dataForRecos = ['statistiques' => $stats]; 
            $recommandations = $this->genererRecommandations($dataForRecos);
            
            $conseils = [];
            if (!empty($recommandations)) {
                $conseils[] = [
                    'type' => 'Performance & Conseils',
                    'recommandations' => $recommandations
                ];
            }
    
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Note Élève',
                        'data' => $dataEleve,
                        'borderColor' => '#4CAF50', // Green
                    ],
                    [
                        'label' => 'Moyenne Classe',
                        'data' => $dataClasse,
                        'borderColor' => '#FFC107', // Amber
                    ]
                ],
                'conseils' => $conseils
            ];

        } catch (\Exception $e) {
            Log::error('Erreur analyse notes élève: '.$e->getMessage());
            return null;
        }
    }

    private function getAnalyseNotesClasse($classeId, $matiereId, $anneeScolaire = null)
    {
        if (!$anneeScolaire) $anneeScolaire = \App\Models\Setting::getCurrentAnneeScolaire();
        
        try {
             // Récupérer les moyennes de classe par trimestre
            $moyennes_classe = Note::where('classe_id', $classeId)
                ->where('matiere_id', $matiereId)
                ->where('annee_scolaire', $anneeScolaire)
                ->select('trimestre', DB::raw('AVG(moyenne_trimestrielle) as moyenne'))
                ->groupBy('trimestre')
                ->orderBy('trimestre')
                ->get();

            if ($moyennes_classe->isEmpty()) {
                return null;
            }

            $labels = [];
            $data_values = [];

            foreach ($moyennes_classe as $stat) {
                $labels[] = "Trimestre {$stat->trimestre}";
                $data_values[] = round($stat->moyenne, 2);
            }

            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Moyenne Classe',
                        'data' => $data_values,
                        'borderColor' => '#2196F3', // Blue
                    ]
                ],
                'conseils' => [
                    [
                        'type' => 'Vue d\'ensemble',
                        'recommandations' => [
                            'Ceci est une vue globale de la classe.',
                            'Sélectionnez un élève pour voir ses performances détaillées et obtenir des conseils personnalisés.'
                        ]
                    ]
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Erreur analyse notes classe: '.$e->getMessage());
            return null;
        }
    }

    private function calculerTendance($moyennes)
    {
        if (count($moyennes) < 2) {
            return 'stable';
        }

        $derniere = end($moyennes);
        $precedente = prev($moyennes);

        if ($derniere > $precedente + 0.5) {
            return 'progressif';
        } elseif ($derniere < $precedente - 0.5) {
            return 'regressif';
        } else {
            return 'stable';
        }
    }

    private function genererRecommandations($data)
    {
        $recommandations = [];
        $moyenne = $data['statistiques']['moyenne_generale'] ?? 0;
        $tendance = $data['statistiques']['tendance'] ?? 'stable';

        if ($moyenne >= 15) {
            $recommandations[] = 'Excellentes performances! Continuez à maintenir ce niveau.';
            $recommandations[] = "Envisagez d'aider vos camarades ou d'explorer des sujets plus avancés.";
        } elseif ($moyenne >= 12) {
            $recommandations[] = 'Bon travail! Vos résultats sont satisfaisants.';
            $recommandations[] = 'Concentrez-vous sur la régularité pour progresser encore.';
        } elseif ($moyenne >= 10) {
            $recommandations[] = 'Résultats passables. Essayez de vous exercer davantage.';
            $recommandations[] = "N'hésitez pas à poser des questions en classe.";
        } else {
            $recommandations[] = 'Attention nécessaire. Vous devriez revoir les bases.';
            $recommandations[] = 'Envisagez un soutien supplémentaire.';
        }

        if ($tendance === 'progressif') {
            $recommandations[] = 'Félicitations pour votre nette progression!';
        } elseif ($tendance === 'regressif') {
            $recommandations[] = 'Vos résultats ont baissé. Identifiez les difficultés et travaillez à les surmonter.';
        }

        return $recommandations;
    }

    // Méthode pour générer les graphiques en base64
    private function generateCharts($analyseData)
    {
        $charts = [];

        // Graphique 1: Évolution des moyennes (élève vs classe)
        if (! empty($analyseData['trimestres'])) {
            $charts['evolution'] = $this->generateEvolutionChart(
                $analyseData['trimestres'],
                $analyseData['moyennes_eleve'],
                $analyseData['moyennes_classe']
            );
        }

        // Graphique 2: Répartition des notes
        if (! empty($analyseData['notes_interros']) || ! empty($analyseData['notes_devoirs'])) {
            $charts['repartition'] = $this->generateRepartitionChart(
                $analyseData['notes_interros'],
                $analyseData['notes_devoirs']
            );
        }

        return $charts;
    }

    // Méthodes pour générer les images de graphiques (implémentation basique)
    private function generateEvolutionChart($trimestres, $moyennesEleve, $moyennesClasse)
    {
        // Cette méthode générerait normalement une image de graphique
        // Pour cette démo, nous retournons un placeholder
        return 'data:image/svg+xml;base64,'.base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">
            <rect width="100%" height="100%" fill="#f8f9fa"/>
            <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14">
                Graphique d\'évolution des moyennes
            </text>
            <text x="50%" y="65%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="12" fill="#6c757d">
                (Trimestres: '.implode(', ', $trimestres).')
            </text>
        </svg>
    ');
    }

    private function generateRepartitionChart($notesInterros, $notesDevoirs)
    {
        // Cette méthode générerait normalement une image de graphique
        return 'data:image/svg+xml;base64,'.base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">
            <rect width="100%" height="100%" fill="#f8f9fa"/>
            <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14">
                Graphique de répartition des notes
            </text>
            <text x="50%" y="65%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="12" fill="#6c757d">
                (Interros: '.count($notesInterros).', Devoirs: '.count($notesDevoirs).')
            </text>
        </svg>
    ');
    }

    public function getMatieresByClasse($classeId)
    {
        try {
            $professeur = Auth::user();

            // Vérifier que le professeur a accès à cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'Accès non autorisé'], 403);
            }

            $classe = Classe::with(['matieres' => function ($query) use ($professeur) {
                $query->wherePivot('professeur_id', $professeur->id)
                    ->orderBy('pivot_ordre_affichage');
            }])->findOrFail($classeId);

            $matieres = $classe->matieres;

            // Fallback: Si aucune matière n'est trouvée via le pivot strict,
            // on ajoute la matière principale du professeur s'il en a une.
            if ($matieres->isEmpty() && $professeur->matiere_id) {
                $mainMatiere = Matiere::find($professeur->matiere_id);
                if ($mainMatiere) {
                    $matieres->push($mainMatiere);
                }
            }

            return response()->json([
                'success' => true,
                'matieres' => $matieres,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur getMatieresByClasse: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des matières',
            ], 500);
        }
    }

    public function getElevesByClasse($classeId)
    {
        try {
            $professeur = Auth::user();

            // Vérifier que le professeur a accès à cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'Accès non autorisé'], 403);
            }

            // Charger les élèves de la classe
            $classe = Classe::with(['eleves' => function ($query) {
                $query->orderBy('nom')->orderBy('prenom');
            }])->findOrFail($classeId);

            return response()->json([
                'success' => true,
                'eleves' => $classe->eleves,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des élèves: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des élèves',
            ], 500);
        }
    }

    public function getPresencesByClasse(Request $request, $classeId)
    {
        try {
            $professeur = Auth::user();

            // Vérifier que le professeur a accès à cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'Accès non autorisé'], 403);
            }

            // Déduction stricte de la matière (comme pour AnalyseNotes)
            $matiere = $professeur->classes()
                ->where('classe_id', $classeId)
                ->first()
                ->pivot
                ->matiere_id ?? $professeur->matiere_id;

            $date = $request->query('date', now()->format('Y-m-d'));

            // Récupérer les présences pour cette classe, cette date ET cette matière
            $query = \App\Models\Presence::where('classe_id', $classeId)
                ->whereDate('date', $date)
                ->with('eleve');

            // Filtrer par matière si trouvée
            if ($matiere) {
                $query->where('cours_id', $matiere);
            }

            $presences = $query->get()->keyBy('eleve_id');

            return response()->json([
                'success' => true,
                'presences' => $presences,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des présences: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des présences',
            ], 500);
        }
    }

    public function storePresences(Request $request)
    {
        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'date' => 'required|date',
            'absents' => 'present|array',
            'absents.*' => 'exists:eleves,id',
            'remarques_generales' => 'nullable|string',
        ]);

        $professeur = Auth::user();

        // 1. Vérification stricte de l'accès à la classe
        if (!$professeur->classes->contains($request->classe_id)) {
            return response()->json(['error' => 'Vous n\'êtes pas assigné à cette classe.'], 403);
        }

        // 2. Déduction stricte de la matière
        $matiere = $professeur->classes()
            ->where('classe_id', $request->classe_id)
            ->first()
            ->pivot
            ->matiere_id ?? $professeur->matiere_id;

        if (!$matiere) {
             return response()->json(['error' => 'Aucune matière associée à votre profil pour cette classe.'], 422);
        }

        DB::beginTransaction();
        try {
            $eleves = Eleve::where('classe_id', $request->classe_id)->get();

            foreach ($eleves as $eleve) {
                $isAbsent = in_array($eleve->id, $request->absents);
                
                $presence = \App\Models\Presence::updateOrCreate(
                    [
                        'eleve_id' => $eleve->id,
                        'classe_id' => $request->classe_id,
                        'date' => $request->date,
                        'cours_id' => $matiere // Constraint relâchée via migration
                    ],
                    [
                        'professeur_id' => $professeur->id,
                        'present' => !$isAbsent,
                        'remarque' => $isAbsent ? 'Absent' : null,
                    ]
                );

                $isNewAbsent = $isAbsent && ($presence->wasRecentlyCreated || $presence->wasChanged('present'));

                if ($isNewAbsent && !empty($eleve->repetiteur_whatsapp)) {
                    $texteWhatsapp = "❌ *Alerte Absence*\n\n";
                    $texteWhatsapp .= "Élève : *{$eleve->nom_complet}*\n";
                    $texteWhatsapp .= "Date : *" . \Carbon\Carbon::parse($request->date)->format('d/m/Y') . "*\n\n";
                    $texteWhatsapp .= "L'élève a été marqué absent en cours.\n";
                    $texteWhatsapp .= "Nous vous prions de vérifier s'il s'agit d'une raison justifiée ou non.";

                    try {
                        \Illuminate\Support\Facades\Http::timeout(3)->post(env('WHATSAPP_BOT_URL', 'http://localhost:3000') . '/send', [
                            'phone' => $eleve->repetiteur_whatsapp,
                            'message' => $texteWhatsapp
                        ]);
                    } catch (\Exception $reqEx) {
                        \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Absence) : ' . $reqEx->getMessage());
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Présences enregistrées avec succès.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur enregistrement présences: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher le formulaire de demande de code
     */
    public function showForgotPasswordForm()
    {
        return response()->json(['message' => 'Please use the frontend to request password reset.']);
    }

    /**
     * Générer et envoyer le code secret
     */
    public function sendResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:direction,email',
        ], [
            'email.exists' => 'Aucun compte trouvé avec cette adresse email.',
        ]);

        // Vérifier d'abord si l'utilisateur existe
        $user = Direction::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Utilisateur non trouvé.'], 404);
        }

        // Générer un code à 6 chiffres
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Supprimer les anciens codes pour cet email
        PasswordResetCode::where('email', $request->email)->delete();

        // Créer un nouveau code avec expiration (15 minutes)
        PasswordResetCode::create([
            'email' => $request->email,
            'code' => $code,
            'created_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            // Envoyer la notification avec le code
            $user->notify(new PasswordResetCodeNotification($code));

            return response()->json([
                'success' => true,
                'message' => 'Code de réinitialisation envoyé avec succès.',
                'email' => $request->email,
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi du code. Veuillez réessayer.'], 500);
        }
    }

    /**
     * Afficher le formulaire de vérification du code
     */
    public function showVerifyCodeForm()
    {
        return response()->json(['message' => 'Please use the frontend to verify code.']);
    }

    /**
     * Vérifier le code secret
     */
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $resetCode = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        if (! $resetCode) {
            return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.'], 400);
        }

        // Code valide
        return response()->json([
            'success' => true,
            'message' => 'Code valide.',
            'email' => $request->email,
            'code' => $request->code,
        ]);
    }

    /**
     * Afficher le formulaire de réinitialisation
     */
    public function showResetForm(Request $request)
    {
        return response()->json(['message' => 'Please use the frontend to reset password.']);
    }

    /**
     * Réinitialiser le mot de passe
     */
    /**
     * Réinitialiser le mot de passe
     */
    /**
     * Réinitialiser le mot de passe (personal_code)
     */
    /**
     * Réinitialiser le personal_code (mot de passe)
     */
    public function resetPassword(Request $request)
    {
        \Log::info('=== DÉBUT RÉINITIALISATION ===');
        \Log::info('Données reçues:', $request->all());

        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'personal_code' => ['required', 'confirmed', 'min:6'], // Ajout de confirmed et min
        ], [
            'personal_code.confirmed' => 'La confirmation du code personnel ne correspond pas.',
            'personal_code.min' => 'Le code personnel doit contenir au moins 6 caractères.',
        ]);

        // Vérifier à nouveau le code
        $resetCode = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        \Log::info('Code de reset trouvé:', ['exists' => (bool) $resetCode]);

        if (! $resetCode) {
            \Log::warning('Code invalide ou expiré');

            return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.'], 400);
        }

        // Trouver l'utilisateur
        $user = Professeur::where('email', $request->email)->first();
        \Log::info('Utilisateur trouvé:', ['exists' => (bool) $user, 'id' => $user?->id]);

        if ($user) {
            // Avant la mise à jour
            \Log::info('Avant mise à jour - personal_code actuel:', ['current_code' => $user->personal_code]);

            try {
                // Mettre à jour le personal_code
                $user->update([
                    'personal_code' => Hash::make($request->personal_code),
                ]);

                // Recharger l'utilisateur pour vérifier
                $user->refresh();
                \Log::info('Après mise à jour - personal_code nouveau:', ['new_code' => $user->personal_code]);

                // Vérifier si le hash correspond
                $isValid = Hash::check($request->personal_code, $user->personal_code);
                \Log::info('Vérification hash:', ['is_valid' => $isValid]);

                // Supprimer le code utilisé
                PasswordResetCode::where('email', $request->email)->delete();

                \Log::info('=== RÉINITIALISATION RÉUSSIE ===');

                // Rediriger avec un message de succès
                return response()->json([
                    'success' => true,
                    'message' => 'Code personnel réinitialisé avec succès. Vous pouvez maintenant vous connecter.',
                ]);

            } catch (\Exception $e) {
                \Log::error('Erreur lors de la mise à jour: '.$e->getMessage());

                return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour: '.$e->getMessage()], 500);
            }
        }

        \Log::error('Utilisateur non trouvé');

        return response()->json(['success' => false, 'message' => 'Utilisateur non trouvé.'], 404);
    }

    /**
     * Renvoyer un nouveau code
     */
    public function resendCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:direction,email',
        ]);

        // Vérifier si l'utilisateur existe
        $user = Direction::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Utilisateur non trouvé.'], 404);
        }

        // Générer un nouveau code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Supprimer les anciens codes
        PasswordResetCode::where('email', $request->email)->delete();

        // Créer le nouveau code
        PasswordResetCode::create([
            'email' => $request->email,
            'code' => $code,
            'created_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            // Envoyer la notification
            $user->notify(new PasswordResetCodeNotification($code));

            return response()->json([
                'success' => true,
                'message' => 'Nouveau code envoyé avec succès.',
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi du code.'], 500);
        }
    }

    public function cahierTexte()
    {
        $professeur = Auth::user();

        // Charger toutes les matières que le prof enseigne dans ses classes
        $professeur->load(['classes.matieres' => function ($q) use ($professeur) {
            $q->wherePivot('professeur_id', $professeur->id);
        }]);

        // Récupérer les ID des matières enseignées par ce prof
        // (En général une seule, mais supporte le multi-matière)
        $matiereIds = collect();
        foreach ($professeur->classes as $classe) {
            foreach ($classe->matieres as $matiere) {
                $matiereIds->push($matiere->id);
            }
        }
        
        // Fallback: Si pas de matière pivot, utiliser la matière du profil
        if ($matiereIds->isEmpty() && $professeur->matiere_id) {
            $matiereIds->push($professeur->matiere_id);
        }

        $cahiers = CahierTexte::whereIn('classe_id', $professeur->classes->pluck('id'))
            ->where('professeur_id', $professeur->id)
            // FILTRE STRICT: Seulement pour les matières du prof
            ->whereIn('matiere_id', $matiereIds->unique()) 
            ->with(['classe' => function ($q) {
                $q->select('id', 'nom');
            }])
            ->with('matiere') // Eager load subject name
            ->orderBy('date_cours', 'desc')
            ->take(50)
            ->get();

        return response()->json([
            'success' => true,
            'cahiers' => $cahiers,
        ]);
    }

    public function storeCahierTexte(Request $request)
    {
        $professeur = Auth::user();

        $request->validate([
            'classe_id' => 'required|exists:classes,id',
            'date_cours' => 'required|date',
            'duree_cours' => 'required|integer|min:1',
            'heure_debut' => 'required|string',
            'notion_cours' => 'required|string',
            'contenu_cours' => 'nullable|string',
            'observations' => 'nullable|string',
            'travail_a_faire' => 'nullable|string',
        ]);

        if (! $professeur->classes->contains($request->classe_id)) {
            return response()->json(['success' => false, 'message' => 'Non autorisé pour cette classe.'], 403);
        }

        // Déduction stricte de la matière
        $matiere_id = $professeur->classes()
            ->where('classe_id', $request->classe_id)
            ->first()
            ->pivot
            ->matiere_id ?? $professeur->matiere_id;

        if (!$matiere_id) {
             return response()->json(['success' => false, 'message' => 'Aucune matière associée.'], 422);
        }

        $cahier = CahierTexte::create([
            'classe_id' => $request->classe_id,
            'professeur_id' => $professeur->id,
            'matiere_id' => $matiere_id, // ENFIN STRICTEMENT LIÉ
            'date_cours' => $request->date_cours,
            'duree_cours' => $request->duree_cours,
            'heure_debut' => $request->heure_debut,
            'notion_cours' => $request->notion_cours,
            'contenu_cours' => $request->input('contenu_cours', ''),
            'observations' => $request->input('observations', ''),
            'objectifs' => '', 
            'travail_a_faire' => $request->input('travail_a_faire', ''),
        ]);

        $cahier->load(['classe', 'matiere']);

        if (!empty($cahier->travail_a_faire)) {
            $tuteurs = collect();
            $eleves = \App\Models\Eleve::where('classe_id', $cahier->classe_id)->with('tuteurs')->get();
            foreach ($eleves as $eleve) {
                foreach ($eleve->tuteurs as $tuteur) {
                    $tuteurs->push($tuteur);
                }

                // --- WHATSAPP REPETITEUR ---
                if (!empty($eleve->repetiteur_whatsapp)) {
                    $texteWhatsapp = "📚 *Nouveau Devoir à faire*\n\n";
                    $texteWhatsapp .= "Élève : *{$eleve->nom_complet}*\n";
                    $texteWhatsapp .= "Matière : *{$cahier->matiere->nom}*\n";
                    $texteWhatsapp .= "Pour le : *" . \Carbon\Carbon::parse($cahier->date_cours)->format('d/m/Y') . "*\n\n";
                    $texteWhatsapp .= "Travail à faire : _{$cahier->travail_a_faire}_";

                    try {
                        \Illuminate\Support\Facades\Http::timeout(3)->post(env('WHATSAPP_BOT_URL', 'http://localhost:3000') . '/send', [
                            'phone' => $eleve->repetiteur_whatsapp,
                            'message' => $texteWhatsapp
                        ]);
                    } catch (\Exception $reqEx) {
                        \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp : ' . $reqEx->getMessage());
                    }
                }
            }
            $tuteurs = $tuteurs->unique('id');
            \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\NouvelExerciceNotification($cahier));
        }

        return response()->json([
            'success' => true,
            'message' => 'Cahier de texte enregistré avec succès.',
            'cahier' => $cahier,
        ]);
    }

    public function getExercices()
    {
        $professeur = Auth::user();

        $cahiers = CahierTexte::where('professeur_id', $professeur->id)
            ->whereNotNull('travail_a_faire')
            ->where('travail_a_faire', '!=', '')
            ->with(['classe' => function ($q) {
                $q->select('id', 'nom');
            }, 'matiere', 'elevesNonFaits'])
            ->orderBy('date_cours', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'exercices' => $cahiers,
        ]);
    }

    public function markExerciceNonFait(Request $request, $id)
    {
        $professeur = Auth::user();
        
        $request->validate([
            'eleves_ids' => 'array',
            'eleves_ids.*' => 'exists:eleves,id',
        ]);

        $cahier = CahierTexte::where('id', $id)->where('professeur_id', $professeur->id)->firstOrFail();

        $cahier->elevesNonFaits()->sync($request->input('eleves_ids', []));
        /** @var \Illuminate\Database\Eloquent\Collection|\App\Models\Eleve[] $eleves */
        $eleves = \App\Models\Eleve::whereIn('id', $request->input('eleves_ids', []))->with('tuteurs')->get();
        foreach ($eleves as $eleve) {
            foreach ($eleve->tuteurs as $tuteur) {
                $tuteur->notify(new \App\Notifications\ExerciceNonFaitNotification($eleve, $cahier));
            }

            // --- WHATSAPP REPETITEUR ---
            if (!empty($eleve->repetiteur_whatsapp)) {
                $texteWhatsapp = "⚠️ *Alerte Exercice Non Fait*\n\n";
                $texteWhatsapp .= "Élève : *{$eleve->nom_complet}*\n";
                $texteWhatsapp .= "Matière : *{$cahier->matiere->nom}*\n\n";
                $texteWhatsapp .= "L'élève n'a pas fait l'exercice demandé : _{$cahier->travail_a_faire}_. Merci de veiller à ce que cela soit fait.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(3)->post(env('WHATSAPP_BOT_URL', 'http://localhost:3000') . '/send', [
                        'phone' => $eleve->repetiteur_whatsapp,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp : ' . $reqEx->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Statut des exercices mis à jour et parents notifiés.',
        ]);
    }

    public function destroyCahierTexte($id)
    {
        $professeur = Auth::user();
        $cahier = CahierTexte::find($id);

        if (! $cahier) {
            return response()->json(['success' => false, 'message' => 'Entrée non trouvée.'], 404);
        }

        if ($cahier->professeur_id !== $professeur->id) {
            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        $cahier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Entrée supprimée avec succès.',
        ]);
    }

    /**
     * Récupérer les classes du professeur connecté
     */
    public function mesClasses()
    {
        try {
            $professeur = Auth::user();

            if (! $professeur) {
                return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
            }

            // Charger les classes avec les relations nécessaires
            $classes = $professeur->classes()
                ->with(['professeurPrincipal', 'matieres' => function ($q) {
                    // Pour l'instant, toutes les matières de la classe sont utiles pour le contexte
                }])
                ->withCount('eleves')
                ->orderBy('niveau')
                ->orderBy('nom')
                ->get();

            return response()->json([
                'success' => true,
                'classes' => $classes,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération classes prof: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des classes.',
            ], 500);
        }
    }
}
