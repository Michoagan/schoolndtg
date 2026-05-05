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
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('professeurs')->whereNull('deleted_at')],
            'phone' => 'required|string|max:20',
            'matiere_id' => 'required|exists:matieres,id',
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $personalCode = strtoupper(substr($validated['last_name'], 0, 5)).rand(1000, 9999);

        // GÃ©rer l'upload de la photo
        $photoName = null;
        if ($request->hasFile('photo')) {
            $firebaseStorage = new \App\Services\FirebaseStorageService();
            $url = $firebaseStorage->uploadFile($request->file('photo'), 'professeurs');
            if ($url) {
                $photoName = $url;
            }
        }

        // CrÃ©er le professeur
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

        // Attacher les matiÃ¨res (Legacy support if needed, but we are moving to 1-1)
        // $professeur->matieres()->attach($validated['matieres']);

        // Envoyer la notification avec le code personnel EN CLAIR
        try {
            $professeur->notify(new ProfessorAccountCreatedNotification($professeur, $personalCode));
        } catch (\Throwable $e) {
            Log::error('Erreur d\'envoi d\'email lors de la crÃ©ation de professeur: ' . $e->getMessage());
        }

        // --- ENVOI WHATSAPP AUTOMATIQUE AU PROFESSEUR ---
        if (!empty($professeur->phone)) {
            $texteWhatsapp = "Bienvenue À NDTG, Professeur {$professeur->first_name} {$professeur->last_name} !\n\n";
            $texteWhatsapp .= "Votre compte a été créé avec succès.\n";
            $texteWhatsapp .= "Vos identifiants :\n";
            $texteWhatsapp .= "- Email : {$professeur->email}\n";
            $texteWhatsapp .= "- Code personnel : {$personalCode}\n\n";
            $texteWhatsapp .= "Veuillez conserver ce code précieusement. Il vous servira pour vous connecter à l'application.";

            try {
                \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                    'phone' => $professeur->phone,
                    'message' => $texteWhatsapp
                ]);
            } catch (\Throwable $reqEx) {
                Log::error('Erreur HTTP vers Bot WhatsApp (CrÃ©ation Prof) : ' . $reqEx->getMessage());
            }
        }

        // RÃ©ponse JSON au lieu de redirect
        return response()->json([
            'success' => true,
            'message' => 'Professeur inscrit avec succÃ¨s! Un email avec le code personnel a Ã©tÃ© envoyÃ©.',
            'data' => $professeur,
        ], 201);
    }

    /**
     * Afficher la liste des professeurs
     */
    // Dans votre mÃ©thode index() ou show() du contrÃ´leur
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
            Log::error('Erreur lors de la rÃ©cupÃ©ration des professeurs: '.$e->getMessage());

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
     * Mettre Ã  jour un professeur
     */

    /**
     * Supprimer un professeur
     */
    public function destroy(Professeur $professeur)
    {
        try {
            // Supprimer la photo
            if ($professeur->photo) {
                if (strpos($professeur->photo, 'firebasestorage') !== false) {
                    $firebaseStorage = new \App\Services\FirebaseStorageService();
                    $firebaseStorage->deleteFile($professeur->photo);
                } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists('professeurs/'.$professeur->photo)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete('professeurs/'.$professeur->photo);
                }
            }

            $professeur->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Professeur supprimÃ© avec succÃ¨s!',
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
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('professeurs')->ignore($professeur->id)->whereNull('deleted_at')],
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
                    if (strpos($professeur->photo, 'firebasestorage') !== false) {
                        $firebaseStorage = new \App\Services\FirebaseStorageService();
                        $firebaseStorage->deleteFile($professeur->photo);
                    } elseif (\Illuminate\Support\Facades\Storage::disk('public')->exists('professeurs/'.$professeur->photo)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete('professeurs/'.$professeur->photo);
                    }
                }

                $firebaseStorage = new \App\Services\FirebaseStorageService();
                $url = $firebaseStorage->uploadFile($request->file('photo'), 'professeurs');
                if ($url) {
                    $professeur->photo = $url;
                }
            }

            $professeur->update($request->except(['photo', 'personal_code']));

            return response()->json([
                'success' => true,
                'message' => 'Professeur modifiÃ© avec succÃ¨s',
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

        // VÃ©rifier si le professeur existe avec cet email
        $professeur = Professeur::where('email', $credentials['email'])->first();

        if (! $professeur) {
            return back()->withErrors([
                'email' => 'Aucun professeur trouvÃ© avec cet email.',
            ])->withInput();
        }

        // VÃ©rifier le code personnel
        // VÃ©rifier le code personnel
        if (! Hash::check($credentials['personal_code'], $professeur->personal_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Code personnel incorrect.',
            ], 401);
        }

        // CrÃ©er un token Sanctum
        $token = $professeur->createToken('professeur_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion rÃ©ussie',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $professeur,
        ]);
    }

    public function logout()
    {
        // RÃ©voquer le token actuel
        if (Auth::guard('sanctum')->check()) {
            /** @var \Laravel\Sanctum\PersonalAccessToken $token */
            $token = Auth::guard('sanctum')->user()->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'DÃ©connexion rÃ©ussie',
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
     * Signaler un exercice non fait pour un Ã©lÃ¨ve
     */
    public function signalerExerciceNonFait(Request $request)
    {
        $request->validate([
            'eleve_id' => 'required|exists:eleves,id',
        ]);

        $professeur = Auth::user();

        if (!$professeur instanceof \App\Models\Professeur) {
            return response()->json(['error' => 'Non autorisÃ©'], 403);
        }

        try {
            $eleve = \App\Models\Eleve::findOrFail($request->eleve_id);
            
            // RÃ©cupÃ©rer les tuteurs de l'Ã©lÃ¨ve
            $tuteurs = $eleve->tuteurs;

            if ($tuteurs->isNotEmpty()) {
                // Envoyer la notification
                \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\ExerciceNonFaitNotification($eleve));
            }

            $texteWhatsapp = "Alerte Exercice Non Fait\n\n";
            $texteWhatsapp .= "Élève : {$eleve->nom_complet}\n";
            $texteWhatsapp .= "Le professeur {$professeur->nom} {$professeur->prenom} signale que votre enfant n'a pas fait son exercice aujourd'hui.\n\n";
            $texteWhatsapp .= "Merci de suivre cela de près.";

            // --- ENVOI WHATSAPP AUTOMATIQUE AU REPETITEUR ---
            if (!empty($eleve->repetiteur_whatsapp)) {
                try {
                    \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $eleve->repetiteur_whatsapp,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp : ' . $reqEx->getMessage());
                }
            }

            // --- ENVOI WHATSAPP AUTOMATIQUE AUX PARENTS ---
            foreach ($tuteurs as $tuteur) {
                if (!empty($tuteur->telephone)) {
                    try {
                        \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                            'phone' => $tuteur->telephone,
                            'message' => $texteWhatsapp
                        ]);
                    } catch (\Exception $reqEx) {
                        \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Parent) : ' . $reqEx->getMessage());
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'L\'exercice non fait a Ã©tÃ© signalÃ© aux parents.',
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
     * Changer le code personnel (AuthentifiÃ©)
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
            'message' => 'Code personnel modifiÃ© avec succÃ¨s.',
        ]);
    }

    /**
     * Demande de rÃ©initialisation de code (Public)
     */
    public function forgotCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $professeur = Professeur::where('email', $request->email)->first();

        if (! $professeur) {
            // Pour sÃ©curitÃ©, on dit quand mÃªme envoyÃ©
            return response()->json([
                'success' => true,
                'message' => 'Si cet email existe, un code de rÃ©initialisation a Ã©tÃ© envoyÃ©.',
            ]);
        }

        // GÃ©nÃ©rer un code Ã  6 chiffres
        $code = rand(100000, 999999);

        // Stocker dans password_reset_codes (table commune ou nouvelle)
        PasswordResetCode::updateOrCreate(
            ['email' => $request->email],
            ['code' => $code, 'created_at' => now()]
        );

        // Envoyer le code de réinitialisation par WhatsApp
        if (!empty($professeur->phone)) {
            $texteWhatsapp = "🔐 *Réinitialisation de Code Personnel*\n\n";
            $texteWhatsapp .= "Votre code de récupération est : *$code*\n";
            $texteWhatsapp .= "Ce code est valide pour une courte durée. Ne le partagez avec personne.";

            try {
                \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                    'phone' => $professeur->phone,
                    'message' => $texteWhatsapp
                ]);
            } catch (\Exception $reqEx) {
                Log::error('Erreur HTTP WhatsApp (Forgot Code Prof) : ' . $reqEx->getMessage());
            }
        } else {
            Log::warning("PROF RESET CODE pour {$professeur->email}: $code (Numéro manquant)");
        }

        return response()->json([
            'success' => true,
            'message' => 'Un code de réinitialisation a été envoyé sur votre numéro WhatsApp.',
        ]);
    }

    /**
     * RÃ©initialiser le code avec le token (Public)
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
                'message' => 'Code invalide ou expirÃ©.',
            ], 400);
        }

        $professeur = Professeur::where('email', $request->email)->firstOrFail();
        $professeur->personal_code = Hash::make($request->new_personal_code);
        $professeur->save();

        // Supprimer le code utilisÃ©
        $resetEntry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Votre code personnel a Ã©tÃ© rÃ©initialisÃ© avec succÃ¨s. Vous pouvez vous connecter.',
        ]);
    }

    public function dashboard(Request $request)
    {
        try {
            // Auth via Sanctum
            $professeur = $request->user();
            // ...

            // Charger les classes avec les Ã©lÃ¨ves ET la matiÃ¨re
            $professeur->load(['matiere', 'classes.eleves' => function ($query) {
                $query->orderBy('nom')->orderBy('prenom');
            }]);

            // RÃ©cupÃ©rer les statistiques
            $stats = [
                'classes_count' => $professeur->classes->count(),
                'eleves_count' => $professeur->classes->sum(function ($classe) {
                    return $classe->eleves->count();
                }),
                'cours_semaine' => \App\Models\EmploiDuTemps::where('professeur_id', $professeur->id)->count(),
            ];

            // RÃ©cupÃ©rer les communiquÃ©s rÃ©cents (GÃ©nÃ©ral ou Professeurs)
            $communiques = \App\Models\Communique::whereIn('type', ['general', 'professeurs'])
                ->where('is_published', true)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get();

            // RÃ©cupÃ©rer les Ã©vÃ©nements Ã  venir
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
                return response()->json(['error' => 'Non autorisÃ©'], 403);
            }

            // RÃ©cupÃ©rer l'emploi du temps avec relations
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
                'message' => 'Une erreur est survenue lors de la rÃ©cupÃ©ration de l\'emploi du temps.',
            ], 500);
        }
    }

    public function mesPaiements()
    {
        try {
            $professeur = Auth::user();

            if (! $professeur instanceof Professeur) {
                return response()->json(['error' => 'Non autorisÃ©'], 403);
            }

            // RÃ©cupÃ©rer les paiements gÃ©nÃ©rÃ©s par la comptabilitÃ©
            $paiements = \App\Models\PaiementProfesseur::where('professeur_id', $professeur->id)
                ->orderBy('annee', 'desc')
                ->orderBy('mois', 'desc')
                ->get();

            // Calculer les heures non payÃ©es du mois en cours
            $moisActuel = date('n');
            $anneeActuelle = date('Y');

            $heuresEffectuees = \App\Models\CahierTexte::where('professeur_id', $professeur->id)
                ->whereMonth('date_cours', $moisActuel)
                ->whereYear('date_cours', $anneeActuelle)
                ->whereNull('paiement_id') // S'assurer qu'elles n'ont pas encore Ã©tÃ© rattachÃ©es Ã  un paiement
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

            // Ajouter les primes fixes Ã  l'estimation
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
                'message' => 'Erreur lors de la rÃ©cupÃ©ration des paiements.',
            ], 500);
        }
    }

    public function downloadFichePaie($id)
    {
        $professeur = Auth::user();

        if (! $professeur instanceof Professeur) {
            return response()->json(['error' => 'Non autorisÃ©'], 403);
        }

        $paiement = \App\Models\PaiementProfesseur::with('professeur')
            ->where('professeur_id', $professeur->id)
            ->findOrFail($id);

        $salaire = (object) [
            'annee' => $paiement->annee,
            'mois' => $paiement->mois,
            'professeur_id' => $paiement->professeur_id,
            'direction_user_id' => null,
            'directionUser' => null,
            'professeur' => $paiement->professeur,
            'statut' => $paiement->statut,
            'date_paiement' => $paiement->date_paiement,
            // For the frontend PDF we calculate the average rate if needed
            'taux_horaire' => $paiement->total_heures > 0 ? round($paiement->montant_heures / $paiement->total_heures) : 0,
            'montant_base' => $paiement->montant_heures,
            'heures_travaillees' => $paiement->total_heures,
            'primes' => $paiement->montant_primes,
            'retenues' => 0,
            'net_a_payer' => $paiement->montant_total
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.fiche_paie', [
            'salaire' => $salaire
        ]);
        
        $nom = $paiement->professeur ? str_replace(' ', '_', $paiement->professeur->last_name . '_' . $paiement->professeur->first_name) : 'Anonyme';
        $filename = "fiche_paie_{$paiement->mois}_{$paiement->annee}_{$nom}.pdf";

        return $pdf->download($filename);
    }

    public function matieresParClasse($classeId)
    {
        $professeur = Auth::user();

        // VÃ©rifier que c'est bien un professeur
        if (! $professeur instanceof Professeur) {
            return response()->json(['error' => 'Non autorisÃ©'], 403);
        }
        // ...

        // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe
        if (! $professeur->classes->contains($classeId)) {
            return response()->json(['error' => 'AccÃ¨s non autorisÃ©'], 403);
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
            return response()->json(['error' => 'Non autorisÃ©'], 403);
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

        // 1. Si une classe est sÃ©lectionnÃ©e (filtrage des Ã©lÃ¨ves)
        if ($request->has('classe_id')) {
            $classe_selectionnee = $professeur->classes->firstWhere('id', $request->classe_id);

            if ($classe_selectionnee) {
                // Charger les Ã©lÃ¨ves de cette classe
                $eleves = Eleve::where('classe_id', $classe_selectionnee->id)
                    ->orderBy('nom')
                    ->orderBy('prenom')
                    ->get();
                
                // DÃ©duction stricte de la matiÃ¨re (cohÃ©rent avec getPresences)
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

        // 2. Si un Ã©lÃ¨ve est sÃ©lectionnÃ© -> Lancer l'analyse
        // 2. Si un Ã©lÃ¨ve est sÃ©lectionnÃ© -> Lancer l'analyse Ã©lÃ¨ve
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
            // RÃ©cupÃ©rer les notes de l'Ã©lÃ¨ve
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

            // RÃ©cupÃ©rer toutes les notes de la classe pour calculer les moyennes par devoir/interro
            $notesClasse = Note::where('classe_id', $classeId)
                ->where('matiere_id', $matiereId)
                ->where('annee_scolaire', $anneeScolaire)
                ->get()
                ->groupBy('trimestre');

            $labels = [];
            $dataEleve = [];
            $dataClasse = [];
            $statistiquesNotes = [];

            // DÃ©finition de la structure des Ã©valuations
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

                // Si pas de donnÃ©es pour ce trimestre (ni Ã©lÃ¨ve, ni classe), on saute
                if (! $noteEleve && (! $notesDuTrimestre || $notesDuTrimestre->isEmpty())) {
                    continue;
                }

                foreach ($evaluations as $champ => $labelCourt) {
                    $valeurEleve = $noteEleve ? $noteEleve->$champ : null;
                    
                    // Calcul moyenne classe pour ce champ spÃ©cifique
                    $moyenneClasse = 0;
                    if ($notesDuTrimestre && $notesDuTrimestre->isNotEmpty()) {
                        $avg = $notesDuTrimestre->avg($champ);
                        $moyenneClasse = $avg ? round($avg, 2) : 0;
                    }

                    // On n'ajoute au graphique que les notes que l'Ã©lÃ¨ve a rÃ©ellement composÃ©es
                    if ($valeurEleve !== null) {
                        $labels[] = "T$trimestre $labelCourt";
                        $dataEleve[] = floatval($valeurEleve); 
                        $dataClasse[] = floatval($moyenneClasse);
                        $statistiquesNotes[] = $valeurEleve;
                    }
                }
            }

            // Calculer la vraie moyenne gÃ©nÃ©rale (basÃ©e sur les moyennes trimestrielles)
            $moyennesTrimestrielles = [];
            foreach ($notesEleve as $note) {
                if ($note->moyenne_trimestrielle > 0) {
                    $moyennesTrimestrielles[] = $note->moyenne_trimestrielle;
                }
            }
            $vraiMoyenneGenerale = count($moyennesTrimestrielles) > 0 
                ? round(array_sum($moyennesTrimestrielles) / count($moyennesTrimestrielles), 2) 
                : (count($statistiquesNotes) > 0 ? round(array_sum($statistiquesNotes) / count($statistiquesNotes), 2) : 0);

            // Calculer les statistiques globales
            $stats = [];
            if (! empty($statistiquesNotes)) {
                $stats = [
                    'moyenne_generale' => $vraiMoyenneGenerale,
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

            // GÃ©nÃ©rer les recommandations
            $aiService = app(\App\Services\AiService::class);
            $matiereNom = \App\Models\Matiere::find($matiereId)->nom ?? 'MatiÃ¨re';
            
            $performances = [[
                'matiere' => $matiereNom,
                'moyenne_trimestrielle' => $stats['moyenne_generale'] ?? 0,
            ]];
            
            $aiAdvice = $aiService->analyzeStudentGrades(
                $stats['moyenne_generale'] ?? 0,
                $performances,
                0
            );
            
            $conseils = [];
            if (!empty($aiAdvice) && $aiAdvice !== "L'analyse pÃ©dagogique n'a pas pu Ãªtre gÃ©nÃ©rÃ©e." && strpos($aiAdvice, "Conseil non disponible") === false) {
                $conseils[] = [
                    'type' => 'SynthÃ¨se PÃ©dagogique IA',
                    'recommandations' => [$aiAdvice]
                ];
            } else {
                $dataForRecos = ['statistiques' => $stats]; 
                $recommandations = $this->genererRecommandations($dataForRecos);
                
                if (!empty($recommandations)) {
                    $conseils[] = [
                        'type' => 'Performance & Conseils',
                        'recommandations' => $recommandations
                    ];
                }
            }
    
            return [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Note Ã‰lÃ¨ve',
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
            Log::error('Erreur analyse notes Ã©lÃ¨ve: '.$e->getMessage());
            return null;
        }
    }

    private function getAnalyseNotesClasse($classeId, $matiereId, $anneeScolaire = null)
    {
        if (!$anneeScolaire) $anneeScolaire = \App\Models\Setting::getCurrentAnneeScolaire();
        
        try {
             // RÃ©cupÃ©rer les moyennes de classe par trimestre
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

            $aiService = app(\App\Services\AiService::class);
            $matiereNom = \App\Models\Matiere::find($matiereId)->nom ?? 'Inconnue';
            $classeNom = \App\Models\Classe::find($classeId)->nom ?? 'Inconnue';
            
            $aiAdvice = $aiService->analyzeClassGrades($data_values, $matiereNom, $classeNom);
            
            $conseils = [];
            if (!empty($aiAdvice) && strpos($aiAdvice, 'indisponible') === false) {
                $conseils[] = [
                    'type' => 'SynthÃ¨se PÃ©dagogique IA (Classe)',
                    'recommandations' => [$aiAdvice]
                ];
            } else {
                $conseils[] = [
                    'type' => 'Vue d\'ensemble',
                    'recommandations' => [
                        'Ceci est une vue globale de la classe.',
                        'SÃ©lectionnez un Ã©lÃ¨ve pour voir ses performances dÃ©taillÃ©es et obtenir des conseils personnalisÃ©s.'
                    ]
                ];
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
                'conseils' => $conseils
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
            $recommandations[] = 'Excellentes performances! Continuez Ã  maintenir ce niveau.';
            $recommandations[] = "Envisagez d'aider vos camarades ou d'explorer des sujets plus avancÃ©s.";
        } elseif ($moyenne >= 12) {
            $recommandations[] = 'Bon travail! Vos rÃ©sultats sont satisfaisants.';
            $recommandations[] = 'Concentrez-vous sur la rÃ©gularitÃ© pour progresser encore.';
        } elseif ($moyenne >= 10) {
            $recommandations[] = 'RÃ©sultats passables. Essayez de vous exercer davantage.';
            $recommandations[] = "N'hÃ©sitez pas Ã  poser des questions en classe.";
        } else {
            $recommandations[] = 'Attention nÃ©cessaire. Vous devriez revoir les bases.';
            $recommandations[] = 'Envisagez un soutien supplÃ©mentaire.';
        }

        if ($tendance === 'progressif') {
            $recommandations[] = 'FÃ©licitations pour votre nette progression!';
        } elseif ($tendance === 'regressif') {
            $recommandations[] = 'Vos rÃ©sultats ont baissÃ©. Identifiez les difficultÃ©s et travaillez Ã  les surmonter.';
        }

        return $recommandations;
    }

    // MÃ©thode pour gÃ©nÃ©rer les graphiques en base64
    private function generateCharts($analyseData)
    {
        $charts = [];

        // Graphique 1: Ã‰volution des moyennes (Ã©lÃ¨ve vs classe)
        if (! empty($analyseData['trimestres'])) {
            $charts['evolution'] = $this->generateEvolutionChart(
                $analyseData['trimestres'],
                $analyseData['moyennes_eleve'],
                $analyseData['moyennes_classe']
            );
        }

        // Graphique 2: RÃ©partition des notes
        if (! empty($analyseData['notes_interros']) || ! empty($analyseData['notes_devoirs'])) {
            $charts['repartition'] = $this->generateRepartitionChart(
                $analyseData['notes_interros'],
                $analyseData['notes_devoirs']
            );
        }

        return $charts;
    }

    // MÃ©thodes pour gÃ©nÃ©rer les images de graphiques (implÃ©mentation basique)
    private function generateEvolutionChart($trimestres, $moyennesEleve, $moyennesClasse)
    {
        // Cette mÃ©thode gÃ©nÃ©rerait normalement une image de graphique
        // Pour cette dÃ©mo, nous retournons un placeholder
        return 'data:image/svg+xml;base64,'.base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">
            <rect width="100%" height="100%" fill="#f8f9fa"/>
            <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14">
                Graphique d\'Ã©volution des moyennes
            </text>
            <text x="50%" y="65%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="12" fill="#6c757d">
                (Trimestres: '.implode(', ', $trimestres).')
            </text>
        </svg>
    ');
    }

    private function generateRepartitionChart($notesInterros, $notesDevoirs)
    {
        // Cette mÃ©thode gÃ©nÃ©rerait normalement une image de graphique
        return 'data:image/svg+xml;base64,'.base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">
            <rect width="100%" height="100%" fill="#f8f9fa"/>
            <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="14">
                Graphique de rÃ©partition des notes
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

            // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'AccÃ¨s non autorisÃ©'], 403);
            }

            $classe = Classe::with(['matieres' => function ($query) use ($professeur) {
                $query->wherePivot('professeur_id', $professeur->id)
                    ->orderBy('pivot_ordre_affichage');
            }])->findOrFail($classeId);

            $matieres = $classe->matieres;

            // Fallback: Si aucune matiÃ¨re n'est trouvÃ©e via le pivot strict,
            // on ajoute la matiÃ¨re principale du professeur s'il en a une.
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
                'message' => 'Erreur lors du chargement des matiÃ¨res',
            ], 500);
        }
    }

    public function getElevesByClasse($classeId)
    {
        try {
            $professeur = Auth::user();

            // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'AccÃ¨s non autorisÃ©'], 403);
            }

            // Charger les Ã©lÃ¨ves de la classe
            $classe = Classe::with(['eleves' => function ($query) {
                $query->orderBy('nom')->orderBy('prenom');
            }])->findOrFail($classeId);

            return response()->json([
                'success' => true,
                'eleves' => $classe->eleves,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la rÃ©cupÃ©ration des Ã©lÃ¨ves: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des Ã©lÃ¨ves',
            ], 500);
        }
    }

    public function getPresencesByClasse(Request $request, $classeId)
    {
        try {
            $professeur = Auth::user();

            // VÃ©rifier que le professeur a accÃ¨s Ã  cette classe
            if (! $professeur->classes->contains($classeId)) {
                return response()->json(['error' => 'AccÃ¨s non autorisÃ©'], 403);
            }

            // DÃ©duction stricte de la matiÃ¨re (comme pour AnalyseNotes)
            $matiere = $professeur->classes()
                ->where('classe_id', $classeId)
                ->first()
                ->pivot
                ->matiere_id ?? $professeur->matiere_id;

            $date = $request->query('date', now()->format('Y-m-d'));

            // RÃ©cupÃ©rer les prÃ©sences pour cette classe, cette date ET cette matiÃ¨re
            $query = \App\Models\Presence::where('classe_id', $classeId)
                ->whereDate('date', $date)
                ->with('eleve');

            // Filtrer par matiÃ¨re si trouvÃ©e
            if ($matiere) {
                $query->where('cours_id', $matiere);
            }

            $presences = $query->get()->keyBy('eleve_id');

            return response()->json([
                'success' => true,
                'presences' => $presences,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la rÃ©cupÃ©ration des prÃ©sences: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des prÃ©sences',
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

        // 1. VÃ©rification stricte de l'accÃ¨s Ã  la classe
        if (!$professeur->classes->contains($request->classe_id)) {
            return response()->json(['error' => 'Vous n\'Ãªtes pas assignÃ© Ã  cette classe.'], 403);
        }

        // 2. DÃ©duction stricte de la matiÃ¨re
        $matiere = $professeur->classes()
            ->where('classe_id', $request->classe_id)
            ->first()
            ->pivot
            ->matiere_id ?? $professeur->matiere_id;

        if (!$matiere) {
             return response()->json(['error' => 'Aucune matiÃ¨re associÃ©e Ã  votre profil pour cette classe.'], 422);
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
                        'cours_id' => $matiere // Constraint relÃ¢chÃ©e via migration
                    ],
                    [
                        'professeur_id' => $professeur->id,
                        'present' => !$isAbsent,
                        'remarque' => $isAbsent ? 'Absent' : null,
                    ]
                );

                $isNewAbsent = $isAbsent && ($presence->wasRecentlyCreated || $presence->wasChanged('present'));

                if ($isNewAbsent) {
                    $texteWhatsapp = "Alerte Absence\n\n";
                    $texteWhatsapp .= "Élève : {$eleve->nom_complet}\n";
                    $texteWhatsapp .= "Date : " . \Carbon\Carbon::parse($request->date)->format('d/m/Y') . "\n\n";
                    $texteWhatsapp .= "L'élève a été marqué absent en cours.\n";
                    $texteWhatsapp .= "Nous vous prions de vérifier s'il s'agit d'une raison justifiée ou non.";

                    // Envoi au rÃ©pÃ©titeur
                    if (!empty($eleve->repetiteur_whatsapp)) {
                        try {
                            \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                                'phone' => $eleve->repetiteur_whatsapp,
                                'message' => $texteWhatsapp
                            ]);
                        } catch (\Exception $reqEx) {
                            \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Absence Repetiteur) : ' . $reqEx->getMessage());
                        }
                    }

                    // Envoi aux parents
                    foreach ($eleve->tuteurs as $tuteur) {
                        if (!empty($tuteur->telephone)) {
                            try {
                                \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                                    'phone' => $tuteur->telephone,
                                    'message' => $texteWhatsapp
                                ]);
                            } catch (\Exception $reqEx) {
                                \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Absence Parent) : ' . $reqEx->getMessage());
                            }
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'PrÃ©sences enregistrÃ©es avec succÃ¨s.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur enregistrement prÃ©sences: ' . $e->getMessage());
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
     * GÃ©nÃ©rer et envoyer le code secret
     */
    public function sendResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:professeurs,email',
        ], [
            'email.exists' => 'Aucun professeur trouvé avec cette adresse email.',
        ]);

        // Vérifier d'abord si le professeur existe
        $user = Professeur::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Professeur non trouvé.'], 404);
        }

        // GÃ©nÃ©rer un code Ã  6 chiffres
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Supprimer les anciens codes pour cet email
        PasswordResetCode::where('email', $request->email)->delete();

        // CrÃ©er un nouveau code avec expiration (15 minutes)
        PasswordResetCode::create([
            'email' => $request->email,
            'code' => $code,
            'created_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            // Envoyer la notification avec le code
            // Envoyer le code de rÃ©initialisation par WhatsApp
            if (!empty($user->phone)) {
                $texteWhatsapp = "Réinitialisation de Mot de passe\n\n";
                $texteWhatsapp .= "Votre code secret est : {$code}\n";
                $texteWhatsapp .= "Ce code est valide pour 15 minutes. Ne le partagez avec personne.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $user->phone,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Reset Code Prof) : ' . $reqEx->getMessage());
                }
            } else {
                \Illuminate\Support\Facades\Log::warning("PROF RESET CODE pour {$user->email}: $code (NumÃ©ro manquant)");
            }

            return response()->json([
                'success' => true,
                'message' => 'Code de rÃ©initialisation envoyÃ© avec succÃ¨s.',
                'email' => $request->email,
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi du code. Veuillez rÃ©essayer.'], 500);
        }
    }

    /**
     * Afficher le formulaire de vÃ©rification du code
     */
    public function showVerifyCodeForm()
    {
        return response()->json(['message' => 'Please use the frontend to verify code.']);
    }

    /**
     * VÃ©rifier le code secret
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
            return response()->json(['success' => false, 'message' => 'Code invalide ou expirÃ©.'], 400);
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
     * Afficher le formulaire de rÃ©initialisation
     */
    public function showResetForm(Request $request)
    {
        return response()->json(['message' => 'Please use the frontend to reset password.']);
    }

    /**
     * RÃ©initialiser le mot de passe
     */
    /**
     * RÃ©initialiser le mot de passe
     */
    /**
     * RÃ©initialiser le mot de passe (personal_code)
     */
    /**
     * RÃ©initialiser le personal_code (mot de passe)
     */
    public function resetPassword(Request $request)
    {
        \Log::info('=== DÃ‰BUT RÃ‰INITIALISATION ===');
        \Log::info('DonnÃ©es reÃ§ues:', $request->all());

        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'personal_code' => ['required', 'confirmed', 'min:6'], // Ajout de confirmed et min
        ], [
            'personal_code.confirmed' => 'La confirmation du code personnel ne correspond pas.',
            'personal_code.min' => 'Le code personnel doit contenir au moins 6 caractÃ¨res.',
        ]);

        // VÃ©rifier Ã  nouveau le code
        $resetCode = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        \Log::info('Code de reset trouvÃ©:', ['exists' => (bool) $resetCode]);

        if (! $resetCode) {
            \Log::warning('Code invalide ou expirÃ©');

            return response()->json(['success' => false, 'message' => 'Code invalide ou expirÃ©.'], 400);
        }

        // Trouver l'utilisateur
        $user = Professeur::where('email', $request->email)->first();
        \Log::info('Utilisateur trouvÃ©:', ['exists' => (bool) $user, 'id' => $user?->id]);

        if ($user) {
            // Avant la mise Ã  jour
            \Log::info('Avant mise Ã  jour - personal_code actuel:', ['current_code' => $user->personal_code]);

            try {
                // Mettre Ã  jour le personal_code
                $user->update([
                    'personal_code' => Hash::make($request->personal_code),
                ]);

                // Recharger l'utilisateur pour vÃ©rifier
                $user->refresh();
                \Log::info('AprÃ¨s mise Ã  jour - personal_code nouveau:', ['new_code' => $user->personal_code]);

                // VÃ©rifier si le hash correspond
                $isValid = Hash::check($request->personal_code, $user->personal_code);
                \Log::info('VÃ©rification hash:', ['is_valid' => $isValid]);

                // Supprimer le code utilisÃ©
                PasswordResetCode::where('email', $request->email)->delete();

                \Log::info('=== RÃ‰INITIALISATION RÃ‰USSIE ===');

                // Rediriger avec un message de succÃ¨s
                return response()->json([
                    'success' => true,
                    'message' => 'Code personnel rÃ©initialisÃ© avec succÃ¨s. Vous pouvez maintenant vous connecter.',
                ]);

            } catch (\Exception $e) {
                \Log::error('Erreur lors de la mise Ã  jour: '.$e->getMessage());

                return response()->json(['success' => false, 'message' => 'Erreur lors de la mise Ã  jour: '.$e->getMessage()], 500);
            }
        }

        \Log::error('Utilisateur non trouvÃ©');

        return response()->json(['success' => false, 'message' => 'Utilisateur non trouvÃ©.'], 404);
    }

    /**
     * Renvoyer un nouveau code
     */
    public function resendCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:direction,email',
        ]);

        // VÃ©rifier si l'utilisateur existe
        $user = Direction::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Utilisateur non trouvÃ©.'], 404);
        }

        // GÃ©nÃ©rer un nouveau code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Supprimer les anciens codes
        PasswordResetCode::where('email', $request->email)->delete();

        // CrÃ©er le nouveau code
        PasswordResetCode::create([
            'email' => $request->email,
            'code' => $code,
            'created_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            // Envoyer le code de rÃ©initialisation par WhatsApp
            if (!empty($user->phone)) {
                $texteWhatsapp = "ðŸ” *RÃ©initialisation de Mot de passe*\n\n";
                $texteWhatsapp .= "Votre code secret est : *$code*\n";
                $texteWhatsapp .= "Ce code est valide pour 15 minutes. Ne le partagez avec personne.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $user->phone,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Reset Code Prof) : ' . $reqEx->getMessage());
                }
            } else {
                \Illuminate\Support\Facades\Log::warning("PROF RESET CODE pour {$user->email}: $code (NumÃ©ro manquant)");
            }

            return response()->json([
                'success' => true,
                'message' => 'Nouveau code envoyÃ© avec succÃ¨s.',
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi du code.'], 500);
        }
    }

    public function cahierTexte()
    {
        $professeur = Auth::user();

        // Charger toutes les matiÃ¨res que le prof enseigne dans ses classes
        $professeur->load(['classes.matieres' => function ($q) use ($professeur) {
            $q->wherePivot('professeur_id', $professeur->id);
        }]);

        // RÃ©cupÃ©rer les ID des matiÃ¨res enseignÃ©es par ce prof
        // (En gÃ©nÃ©ral une seule, mais supporte le multi-matiÃ¨re)
        $matiereIds = collect();
        foreach ($professeur->classes as $classe) {
            foreach ($classe->matieres as $matiere) {
                $matiereIds->push($matiere->id);
            }
        }
        
        // Fallback: Si pas de matiÃ¨re pivot, utiliser la matiÃ¨re du profil
        if ($matiereIds->isEmpty() && $professeur->matiere_id) {
            $matiereIds->push($professeur->matiere_id);
        }

        $cahiers = CahierTexte::whereIn('classe_id', $professeur->classes->pluck('id'))
            ->where('professeur_id', $professeur->id)
            // FILTRE STRICT: Seulement pour les matiÃ¨res du prof
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
            return response()->json(['success' => false, 'message' => 'Non autorisÃ© pour cette classe.'], 403);
        }

        // DÃ©duction stricte de la matiÃ¨re
        $matiere_id = $professeur->classes()
            ->where('classe_id', $request->classe_id)
            ->first()
            ->pivot
            ->matiere_id ?? $professeur->matiere_id;

        if (!$matiere_id) {
             return response()->json(['success' => false, 'message' => 'Aucune matiÃ¨re associÃ©e.'], 422);
        }

        $cahier = CahierTexte::create([
            'classe_id' => $request->classe_id,
            'professeur_id' => $professeur->id,
            'matiere_id' => $matiere_id, // ENFIN STRICTEMENT LIÃ‰
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

                $texteWhatsapp = "Nouveau Devoir À faire\n\n";
                $texteWhatsapp .= "Élève : {$eleve->nom_complet}\n";
                $texteWhatsapp .= "Matière : {$cahier->matiere->nom}\n";
                $texteWhatsapp .= "Pour le : " . \Carbon\Carbon::parse($cahier->date_cours)->format('d/m/Y') . "\n\n";
                $texteWhatsapp .= "Travail à faire : {$cahier->travail_a_faire}";

                // --- WHATSAPP REPETITEUR ---
                if (!empty($eleve->repetiteur_whatsapp)) {
                    try {
                        \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                            'phone' => $eleve->repetiteur_whatsapp,
                            'message' => $texteWhatsapp
                        ]);
                    } catch (\Exception $reqEx) {
                        \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Repetiteur) : ' . $reqEx->getMessage());
                    }
                }

                // --- WHATSAPP PARENTS ---
                foreach ($eleve->tuteurs as $tuteur) {
                    if (!empty($tuteur->telephone)) {
                        try {
                            \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                                'phone' => $tuteur->telephone,
                                'message' => $texteWhatsapp
                            ]);
                        } catch (\Exception $reqEx) {
                            \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Parent) : ' . $reqEx->getMessage());
                        }
                    }
                }
            }
            $tuteurs = $tuteurs->unique('id');
            \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\NouvelExerciceNotification($cahier));
        }

        return response()->json([
            'success' => true,
            'message' => 'Cahier de texte enregistrÃ© avec succÃ¨s.',
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

            $texteWhatsapp = "âš ï¸ *Alerte Exercice Non Fait*\n\n";
            $texteWhatsapp .= "Ã‰lÃ¨ve : *{$eleve->nom_complet}*\n";
            $texteWhatsapp .= "MatiÃ¨re : *{$cahier->matiere->nom}*\n\n";
            $texteWhatsapp .= "L'Ã©lÃ¨ve n'a pas fait l'exercice demandÃ© : _{$cahier->travail_a_faire}_. Merci de veiller Ã  ce que cela soit fait.";

            // --- WHATSAPP REPETITEUR ---
            if (!empty($eleve->repetiteur_whatsapp)) {
                try {
                    \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $eleve->repetiteur_whatsapp,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Repetiteur) : ' . $reqEx->getMessage());
                }
            }

            // --- WHATSAPP PARENTS ---
            foreach ($eleve->tuteurs as $tuteur) {
                if (!empty($tuteur->telephone)) {
                    try {
                        \Illuminate\Support\Facades\Http::timeout(10)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                            'phone' => $tuteur->telephone,
                            'message' => $texteWhatsapp
                        ]);
                    } catch (\Exception $reqEx) {
                        \Illuminate\Support\Facades\Log::error('Erreur HTTP vers Bot WhatsApp (Parent) : ' . $reqEx->getMessage());
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Statut des exercices mis Ã  jour et parents notifiÃ©s.',
        ]);
    }

    public function destroyCahierTexte($id)
    {
        $professeur = Auth::user();
        $cahier = CahierTexte::find($id);

        if (! $cahier) {
            return response()->json(['success' => false, 'message' => 'EntrÃ©e non trouvÃ©e.'], 404);
        }

        if ($cahier->professeur_id !== $professeur->id) {
            return response()->json(['success' => false, 'message' => 'Non autorisÃ©.'], 403);
        }

        $cahier->delete();

        return response()->json([
            'success' => true,
            'message' => 'EntrÃ©e supprimÃ©e avec succÃ¨s.',
        ]);
    }

    /**
     * RÃ©cupÃ©rer les classes du professeur connectÃ©
     */
    public function mesClasses()
    {
        try {
            $professeur = Auth::user();

            if (! $professeur) {
                return response()->json(['success' => false, 'message' => 'Non authentifiÃ©'], 401);
            }

            // Charger les classes avec les relations nÃ©cessaires
            $classes = $professeur->classes()
                ->with(['professeurPrincipal', 'matieres' => function ($q) {
                    // Pour l'instant, toutes les matiÃ¨res de la classe sont utiles pour le contexte
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
            Log::error('Erreur rÃ©cupÃ©ration classes prof: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des classes.',
            ], 500);
        }
    }
}


