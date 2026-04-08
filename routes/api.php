<?php

use App\Http\Controllers\AdminDirectionController;
use App\Http\Controllers\BulletinController;
use App\Http\Controllers\ClasseController;
use App\Http\Controllers\ConduiteController;
use App\Http\Controllers\DirectionAuthController; // Added
use App\Http\Controllers\DirectionController;
use App\Http\Controllers\EleveAuthController; // Added
use App\Http\Controllers\EleveController;
use App\Http\Controllers\MessageAbscenceEController; // Added
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PaiementController;
use App\Http\Controllers\ProfesseurController;
use App\Http\Controllers\RoleController; // Added
use App\Http\Controllers\SurveillantController;
use App\Http\Controllers\TuteurController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// =============================================
// ROUTES PUBLIQUES (Auth & Public Data)
// =============================================

Route::get('/', function () {
    return response()->json(['message' => 'NDTG API is running']);
});

// Admin Auth (Users Table)
Route::prefix('admin')->group(function () {
    Route::post('/login', [\App\Http\Controllers\AdminAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [\App\Http\Controllers\AdminAuthController::class, 'register']);

    Route::middleware('auth:sanctum')->post('/logout', [\App\Http\Controllers\AdminAuthController::class, 'logout']);
});

// Direction Auth
Route::prefix('direction')->group(function () {
    Route::post('/login', [DirectionController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [DirectionController::class, 'register']);

    // Password Reset
    Route::post('/forgot-password', [DirectionController::class, 'sendResetCode'])->middleware('throttle:3,1');
    Route::post('/verify-code', [DirectionController::class, 'verifyResetCode']);
    Route::post('/reset-password', [DirectionController::class, 'resetPassword']);
    Route::post('/resend-code', [DirectionController::class, 'resendCode']);
});

// Professeur Auth
Route::prefix('professeur')->group(function () {
    Route::post('/login', [ProfesseurController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/inscrit', [ProfesseurController::class, 'store']); // Inscription

    // Password/Code Reset
    Route::post('/forgot-code', [ProfesseurController::class, 'forgotCode'])->middleware('throttle:3,1');
    Route::post('/reset-code', [ProfesseurController::class, 'resetCode']);
});

use App\Http\Controllers\SecretaireEpreuveController;

// Eleve Auth (Student Portal)
Route::prefix('eleve')->group(function () {
    Route::post('/register', [\App\Http\Controllers\EleveAuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\EleveAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\EleveAuthController::class, 'logout']);
        Route::get('/notes', [EleveController::class, 'getNotes']);
        Route::get('/epreuves', [EleveController::class, 'getAnciennesEpreuves']);
        Route::get('/exercices', [EleveController::class, 'getExercices']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('professeur')->middleware('role:professeur')->group(function () {
        Route::post('/change-code', [ProfesseurController::class, 'changeCode']);
        Route::get('/mes-paiements', [ProfesseurController::class, 'mesPaiements']);
        Route::post('/fcm-token', [ProfesseurController::class, 'updateFcmToken']);
        Route::post('/exercice-non-fait', [ProfesseurController::class, 'signalerExerciceNonFait']);
    });
});

// Parent Auth
Route::prefix('parent')->group(function () {
    Route::post('/login', [TuteurController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [TuteurController::class, 'register']);

    // Password Reset (Public)
    Route::post('/forgot-password', [TuteurController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/reset-password', [TuteurController::class, 'resetPassword']);
});

// =============================================
// ROUTES PROTÉGÉES (SANCTUM)
// =============================================

Route::middleware('auth:sanctum')->group(function () {

    // User Info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ====================
    // DIRECTION & ADMIN
    // ====================
    Route::prefix('direction')->middleware('role:direction')->group(function () {
        Route::post('/logout', [DirectionController::class, 'logout']);

        // Notifications
        Route::get('/notifications', [DirectionController::class, 'notifications']);
        Route::post('/notifications/mark-all-read', [DirectionController::class, 'markAllNotificationsAsRead']);
        Route::post('/notifications/{id}/read', [DirectionController::class, 'markNotificationAsRead']);

        // COMPTABILITÉ & INVENTAIRE
        Route::prefix('comptabilite')->middleware('role:comptable,directeur')->group(function () {
            // Dashboard Comptable
            Route::get('/dashboard', [\App\Http\Controllers\ComptabiliteController::class, 'dashboard']); // Entrées vs Sorties

            // Dépenses (Sorties)
            Route::get('/depenses', [\App\Http\Controllers\ComptabiliteController::class, 'index']);
            Route::post('/depenses', [\App\Http\Controllers\ComptabiliteController::class, 'storeDepense']);

            // Les paiements et ventes ont été déplacés vers le prefix 'caisse'.

            // Salaires
            Route::get('/salaires', [\App\Http\Controllers\ComptabiliteController::class, 'salaires']);
            Route::post('/salaires/generate', [\App\Http\Controllers\ComptabiliteController::class, 'generateSalaires']);
            Route::put('/salaires/{id}', [\App\Http\Controllers\ComptabiliteController::class, 'updateSalaire']);
            Route::post('/salaires/{id}/payer', [\App\Http\Controllers\ComptabiliteController::class, 'payerSalaire']);
            Route::get('/salaires/{id}/fiche', [\App\Http\Controllers\ComptabiliteController::class, 'downloadFichePaie']);

            // Paie Professeurs (Nouveau module basé sur Cahier de Texte)
            Route::get('/paie-professeurs/config', [\App\Http\Controllers\Comptabilite\PaieProfesseurController::class, 'getConfiguration']);
            Route::post('/paie-professeurs/config', [\App\Http\Controllers\Comptabilite\PaieProfesseurController::class, 'saveConfiguration']);
            Route::post('/paie-professeurs/generer', [\App\Http\Controllers\Comptabilite\PaieProfesseurController::class, 'genererPaie']);

            // Tranches de Scolarité
            Route::get('/tranches-scolarite', [\App\Http\Controllers\TrancheScolariteController::class, 'index']);
            Route::post('/tranches-scolarite', [\App\Http\Controllers\TrancheScolariteController::class, 'storeOrUpdate']);

            // Inventaire (Articles & Stock)
            Route::get('/articles', [\App\Http\Controllers\InventaireController::class, 'index']); // Liste articles + stock
            Route::post('/articles', [\App\Http\Controllers\InventaireController::class, 'store']); // Créer article
            Route::put('/articles/{article}', [\App\Http\Controllers\InventaireController::class, 'update']);
            Route::post('/articles/{article}/stock', [\App\Http\Controllers\InventaireController::class, 'addStock']); // Approvisionnement
            Route::post('/articles/{article}/correction', [\App\Http\Controllers\InventaireController::class, 'correctStock']); // Inventaire physique
            Route::get('/articles/{article}/historique', [\App\Http\Controllers\InventaireController::class, 'history']); // Mouvements
        });

        // CAISSE (Trésorerie)
        Route::prefix('caisse')->middleware('role:caisse,directeur,comptable')->group(function () {
            // Dashboard Caisse
            Route::get('/dashboard', [\App\Http\Controllers\CaisseController::class, 'dashboard']);

            // Ventes (Autres Recettes)
            Route::post('/ventes', [\App\Http\Controllers\CaisseController::class, 'storeVente']);
            Route::get('/ventes/{vente}/receipt', [\App\Http\Controllers\CaisseController::class, 'downloadVenteReceipt']);
            Route::get('/ventes/{vente}/qrcode', [\App\Http\Controllers\CaisseController::class, 'getVenteQrCode']); // React Endpoint

            // Paiements Scolarité
            Route::get('/paiements', [\App\Http\Controllers\CaisseController::class, 'indexPaiements']);
            Route::post('/paiements', [\App\Http\Controllers\CaisseController::class, 'storePaiement']);
            Route::get('/paiements/{paiement}/receipt', [\App\Http\Controllers\CaisseController::class, 'downloadReceipt']);
            Route::get('/paiements/{paiement}/qrcode', [\App\Http\Controllers\CaisseController::class, 'getPaiementQrCode']); // React Endpoint
        });

        // Tableaux de bord (JSON expected from controllers)
        Route::get('/censeur', [DirectionController::class, 'censeurDashboard'])->middleware('role:censeur,directeur');
        Route::get('/directeur', [\App\Http\Controllers\DirecteurController::class, 'dashboard'])->middleware('role:directeur');

        // Settings Update (Direction only)
        Route::post('/settings', [\App\Http\Controllers\SettingsController::class, 'update'])->middleware('role:directeur,admin');
    });

    // Global Settings (Readable by all authenticated users: Direction, Censeur, Professeur, Secretaire, Parent)
    Route::get('/settings', [\App\Http\Controllers\SettingsController::class, 'index']);

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', [AdminDirectionController::class, 'dashboard']);
        Route::get('/pending-accounts', [AdminDirectionController::class, 'pendingAccounts']);
        Route::get('/all-accounts', [AdminDirectionController::class, 'allAccounts']);
        Route::get('/logs', [AdminDirectionController::class, 'systemLogs']); // Added Global Audit Log
        Route::post('/users', [AdminDirectionController::class, 'store']); // Create User
        Route::post('/account/{id}/approve', [AdminDirectionController::class, 'approveAccount']);
        Route::post('/account/{id}/reject', [AdminDirectionController::class, 'rejectAccount']);
        Route::post('/account/{id}/toggle-status', [AdminDirectionController::class, 'toggleAccountStatus']);
    });

    // ====================
    // CLASSES & MATIERES
    // ====================
    Route::prefix('classes')->group(function () {
        // Lecture : Direction et Professeurs
        Route::middleware('role:direction,professeur')->group(function () {
            Route::get('/index', [ClasseController::class, 'index']);
            Route::get('/{classe}/eleves', [App\Http\Controllers\ProfesseurController::class, 'getElevesByClasse']);
            Route::get('/matieres', [App\Http\Controllers\MatiereController::class, 'index']);
        });

        // Ecriture : Direction uniquement
        Route::middleware('role:direction')->group(function () {
            Route::post('/', [ClasseController::class, 'store']);
            Route::put('/{id}', [ClasseController::class, 'update']);
            Route::delete('/{classe}', [ClasseController::class, 'destroy']);

            Route::post('/matieres', [App\Http\Controllers\MatiereController::class, 'store']);
            Route::put('/matieres/{matiere}', [App\Http\Controllers\MatiereController::class, 'update']);
            Route::delete('/matieres/{matiere}', [App\Http\Controllers\MatiereController::class, 'destroy']);
        });
    });

    // ====================
    // PROFESSEURS
    // ====================
    Route::prefix('professeurs')->group(function () {
        // Management (Direction uniquement)
        Route::middleware('role:direction')->group(function () {
            Route::post('/', [ProfesseurController::class, 'store']); 
            Route::put('/{professeur}', [ProfesseurController::class, 'update']);
            Route::delete('/{professeur}', [ProfesseurController::class, 'destroy']); 
        });

        // Lecture et Actions Pédagogiques (Direction et Professeurs)
        Route::middleware('role:direction,professeur')->group(function () {
            Route::get('/', [ProfesseurController::class, 'index']); 

            // Espace Prof (Self)
            Route::prefix('espace')->group(function () {
                Route::get('/dashboard', [ProfesseurController::class, 'dashboard']);
                Route::post('/logout', [ProfesseurController::class, 'logout']);
                Route::get('/emploi-du-temps', [ProfesseurController::class, 'emploiDuTemps']);
            });

            Route::get('/presences/eleves/{classe}', [ProfesseurController::class, 'getElevesByClasse']);
            Route::get('/classes', [ProfesseurController::class, 'mesClasses']); 
            Route::get('/classes/{classe}/matieres', [ProfesseurController::class, 'getMatieresByClasse']);
            Route::post('/presences', [ProfesseurController::class, 'storePresences']);
            Route::get('/presences/{classe}', [ProfesseurController::class, 'getPresencesByClasse']); 

            // Conduites
            Route::get('/classes/{classe}/conduites', [ConduiteController::class, 'index']);
            Route::post('/classes/{classe}/conduites', [ConduiteController::class, 'store']);
            Route::post('/classes/{classe}/conduites/ia-assistant', [ConduiteController::class, 'genererAppreciationIa']);

            // Performance & IA
            Route::get('/{id}/performance', [\App\Http\Controllers\Api\PerformanceController::class, 'getPerformanceStats']);
            Route::get('/{id}/audit-ia', [\App\Http\Controllers\Api\PerformanceController::class, 'getPerformanceAuditIa']);
        });
    });

    // ====================
    // COMMUNNIQUES
    // ====================
    Route::prefix('communiques')->middleware('role:direction')->group(function () {
        Route::get('/', [\App\Http\Controllers\CommuniqueController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\CommuniqueController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\CommuniqueController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\CommuniqueController::class, 'destroy']);
    });

    Route::get('/notes', [NoteController::class, 'notes'])->middleware('role:professeur,censeur,directeur');
    Route::post('/notes', [NoteController::class, 'storeNotes'])->middleware('role:professeur,censeur,directeur');
    Route::post('/notes/calculer-moyennes', [NoteController::class, 'calculerMoyennes'])->middleware('role:professeur,censeur,directeur');
    Route::get('/analyse-notes', [ProfesseurController::class, 'analyseNotes'])->middleware('role:professeur,censeur,directeur');

    Route::get('/cahier-texte', [ProfesseurController::class, 'cahierTexte'])->middleware('role:professeur,censeur,directeur');
    Route::post('/cahier-texte', [ProfesseurController::class, 'storeCahierTexte'])->middleware('role:professeur,censeur,directeur');
    Route::delete('/cahier-texte/{id}', [ProfesseurController::class, 'destroyCahierTexte'])->middleware('role:professeur,censeur,directeur');
    Route::get('/exercices', [ProfesseurController::class, 'getExercices'])->middleware('role:professeur');
    Route::post('/exercices/{id}/non-faits', [ProfesseurController::class, 'markExerciceNonFait'])->middleware('role:professeur');

    // ====================
    // CENSEUR MODULE
    // ====================
    Route::prefix('censeur')->middleware('role:censeur,directeur')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\CenseurController::class, 'dashboard']);
        Route::get('/logs', [\App\Http\Controllers\CenseurController::class, 'getLogs']);
        Route::get('/suivi', [\App\Http\Controllers\CenseurController::class, 'suiviPedagogique']);

        // Timetable & Programmation
        Route::get('/emplois-du-temps/{classe_id}', [\App\Http\Controllers\CenseurController::class, 'getEmploiDuTemps']);
        Route::post('/emplois-du-temps/{classe_id}', [\App\Http\Controllers\CenseurController::class, 'updateEmploiDuTemps']);
        Route::post('/programmation', [\App\Http\Controllers\CenseurController::class, 'programmation']);
        Route::post('/prof-principal', [\App\Http\Controllers\CenseurController::class, 'setProfPrincipal']);

        // Pédagogie & RH
        Route::get('/contacts', [\App\Http\Controllers\CenseurController::class, 'contacts']);
        Route::get('/cahiers-texte', [\App\Http\Controllers\CenseurController::class, 'cahiersTexte']);

        // Validation
        Route::get('/notes/validation', [\App\Http\Controllers\CenseurController::class, 'getNotesValidationData']);
        Route::post('/notes/validation', [\App\Http\Controllers\CenseurController::class, 'validateNotes']);

        // Compositions (Devoirs)
        Route::get('/session-compositions', [\App\Http\Controllers\SessionCompositionController::class, 'index']);
        Route::post('/session-compositions', [\App\Http\Controllers\SessionCompositionController::class, 'store']);
        Route::delete('/session-compositions/{id}', [\App\Http\Controllers\SessionCompositionController::class, 'destroy']);
    });

    // ====================
    // SECRETAIRE (ELEVES)
    // ====================
    Route::prefix('secretaire')->middleware('role:secretariat,directeur')->group(function () {
        Route::get('/eleves', [EleveController::class, 'index']);
        Route::post('/eleves', [EleveController::class, 'store']);
        Route::post('/eleves/import', [EleveController::class, 'import']); // Added import route
        Route::put('/eleves/{eleve}', [EleveController::class, 'update']);
        Route::delete('/eleves/{eleve}', [EleveController::class, 'destroy']);
        Route::get('/bulletins', [BulletinController::class, 'index']);
        Route::get('/bulletin/eleve/{eleveId}/{trimestre}', [BulletinController::class, 'generatePDF']); // Returns PDF stream

        // Anciennes Epreuves (Secrétariat & Direction)
        Route::get('/epreuves', [SecretaireEpreuveController::class, 'index']);
        Route::post('/epreuves', [SecretaireEpreuveController::class, 'store']);
        Route::delete('/epreuves/{id}', [SecretaireEpreuveController::class, 'destroy']);

        // Notes d'Examens (Blanc et National)
        Route::get('/notes-examens', [\App\Http\Controllers\SecretaireNoteExamenController::class, 'index']);
        Route::post('/notes-examens', [\App\Http\Controllers\SecretaireNoteExamenController::class, 'store']);

        // Evenements (Gérés par le secrétariat)
        Route::get('/evenements', [\App\Http\Controllers\SecretaireController::class, 'evenements']);
        Route::post('/evenements', [\App\Http\Controllers\SecretaireController::class, 'storeEvenement']);
        Route::delete('/evenements/{id}', [\App\Http\Controllers\SecretaireController::class, 'destroyEvenement']);
    });

    // ====================
    // SURVEILLANT
    // ====================
    Route::prefix('surveillant')->middleware('role:surveillant,directeur')->group(function () {
        Route::get('/dashboard', [SurveillantController::class, 'dashboard']); // Added dashboard route
        Route::get('/stats', [SurveillantController::class, 'stats']);
        Route::get('/plaintes', [SurveillantController::class, 'historiquePlaintes']);
        Route::post('/plaintes', [SurveillantController::class, 'storePlainte']);

        // Surveillance lecture des évenements (optionnel mais utile pour le dashboard)
        Route::get('/evenements', [SurveillantController::class, 'evenements']);

        // Présences
        Route::get('/presences/eleves', [SurveillantController::class, 'getPresencesEleves']);
        Route::get('/presences/professeurs', [SurveillantController::class, 'getPresencesProfesseurs']);
    });

    // ====================
    // PARENTS
    // ====================
    Route::prefix('parent')->middleware('role:parent')->group(function () {
        Route::post('/logout', [TuteurController::class, 'logout']);
        Route::post('/change-password', [TuteurController::class, 'changePassword']);
        Route::get('/dashboard', [TuteurController::class, 'dashboard']);
        Route::get('/eleve/{id}', [TuteurController::class, 'showEleve']);
        Route::get('/notes/{eleve_id}', [TuteurController::class, 'getNotes']);
        Route::get('/presences/{eleve_id}', [TuteurController::class, 'getPresences']);
        Route::get('/emploi-du-temps/{eleve_id}', [TuteurController::class, 'emploiDuTemps']);
        Route::get('/convocations/{eleve_id}', [TuteurController::class, 'getConvocations']);
        Route::get('/alertes-scolarite', [TuteurController::class, 'getAlertesScolarite']);
        Route::get('/exercices/{eleve_id}', [TuteurController::class, 'getExercices']);
        Route::get('/professeurs/{eleve_id}', [TuteurController::class, 'getProfesseurs']);
        Route::post('/contact', [TuteurController::class, 'contact'])->middleware('throttle:5,1');

        // Notifications
        Route::get('/notifications', [TuteurController::class, 'getNotifications']);
        Route::post('/notifications/{id}/read', [TuteurController::class, 'markNotificationAsRead']);

        // Paiements
        Route::get('/paiements', [PaiementController::class, 'index']);
        Route::get('/paiements/{id}/receipt', [PaiementController::class, 'generateReceipt']);
        Route::post('/process-payment', [PaiementController::class, 'processPayment']);
        Route::get('/payment/callback/{method}', [PaiementController::class, 'handleCallback'])->name('parent.payment-callback');
        
        // Répétiteur
        Route::post('/eleve/{id}/repetiteur', [TuteurController::class, 'updateRepetiteur']);
        
        // FCM Token
        Route::post('/fcm-token', [TuteurController::class, 'updateFcmToken']);
    });
});
