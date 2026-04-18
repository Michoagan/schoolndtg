<?php

namespace App\Http\Controllers;

use App\Models\CahierTexte;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Paiement;
use App\Models\Professeur;
use App\Models\Setting;
use App\Models\HistoriqueEleve;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDF;

class DirecteurController extends Controller
{
    /**
     * Afficher la liste des classes avec leurs élèves
     */
    public function classesEleves(Request $request)
    {
        // Récupérer tous les niveaux distincts pour le filtre
        $niveaux = Classe::distinct()->pluck('niveau');

        // Récupérer les classes avec le nombre d'élèves et le professeur principal
        $classes = Classe::withCount(['eleves as nombre_eleves'])
            ->with(['professeurPrincipal'])
            ->when($request->niveau, function ($query, $niveau) {
                return $query->where('niveau', $niveau);
            })
            ->orderBy('niveau')
            ->orderBy('nom')
            ->get();

        // Retourner JSON
        return response()->json([
            'success' => true,
            'classes' => $classes,
            'niveaux' => $niveaux,
        ]);
    }

    /**
     * Récupérer les élèves d'une classe spécifique
     */
    public function getElevesByClasse(Classe $classe, Request $request)
    {
        // Charger les élèves avec leurs relations
        $eleves = $classe->eleves()
            ->with(['tuteurs'])
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        // Compter les garçons et les filles
        $stats = [
            'garcons' => $eleves->where('sexe', 'M')->count(),
            'filles' => $eleves->where('sexe', 'F')->count(),
            'total' => $eleves->count(),
        ];

        return response()->json([
            'success' => true,
            'eleves' => $eleves,
            'classe' => $classe->load('professeurPrincipal'),
            'stats' => $stats,
        ]);
    }

    /**
     * Récupérer les détails d'un élève spécifique
     */
    public function getEleveDetails(Eleve $eleve, Request $request)
    {
        // Charger les relations de l'élève
        $eleve->load([
            'classe',
            'tuteurs',
            'paiements' => function ($query) {
                $query->orderBy('date_paiement', 'desc')->limit(5);
            },
        ]);

        return response()->json([
            'success' => true,
            'eleve' => $eleve,
        ]);
    }

    /**
     * Rechercher des élèves
     */
    public function searchEleves(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2',
        ]);

        $searchTerm = $request->search;

        $eleves = Eleve::with(['classe'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('nom', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('prenom', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('matricule', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            })
            ->when($request->classe_id, function ($query, $classeId) {
                return $query->where('classe_id', $classeId);
            })
            ->orderBy('nom')
            ->orderBy('prenom')
            ->limit(50)
            ->get();

        return response()->json([
            'eleves' => $eleves,
            'searchTerm' => $searchTerm,
        ]);
    }

    /**
     * Exporter la liste des élèves d'une classe
     */
    public function exportEleves(Classe $classe, Request $request)
    {
        $eleves = $classe->eleves()
            ->with(['tuteurs'])
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        $format = $request->format ?? 'pdf';

        if ($format === 'pdf') {
            // Génération du QR Code d'authentification
            $qrData = json_encode([
                'type' => 'Liste des Élèves',
                'classe' => $classe->nom,
                'effectif' => $eleves->count(),
                'date' => now()->format('Y-m-d H:i:s'),
                'certifie_par' => 'Notre Dame Pro'
            ]);
            
            $qrResult = \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data($qrData)
                ->size(100)
                ->margin(0)
                ->build();
                
            $qrCodeImage = base64_encode($qrResult->getString());

            $pdf = PDF::loadView('directeur.classes-eleves.export-pdf', [
                'classe' => $classe,
                'eleves' => $eleves,
                'date' => now()->format('d/m/Y'),
                'qrCodeImage' => $qrCodeImage
            ]);

            return $pdf->download('eleves-'.$classe->nom.'-'.now()->format('Y-m-d').'.pdf');
        }

        // Format Excel (CSV)
        if ($format === 'excel') {
            $fileName = 'eleves-'.$classe->nom.'-'.now()->format('Y-m-d').'.csv';

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ];

            $callback = function () use ($eleves, $classe) {
                $file = fopen('php://output', 'w');

                // Entête
                fputcsv($file, [
                    'Liste des élèves - '.$classe->nom,
                    '',
                    '',
                    '',
                ]);

                fputcsv($file, [
                    'Exporté le: '.now()->format('d/m/Y à H:i'),
                    '',
                    '',
                    '',
                ]);

                fputcsv($file, []); // Ligne vide

                // En-têtes des colonnes
                fputcsv($file, [
                    'Matricule',
                    'Nom',
                    'Prénom',
                    'Date de naissance',
                    'Sexe',
                    'Téléphone',
                    'Email',
                    'Parent',
                    'Téléphone parent',
                ]);

                // Données
                foreach ($eleves as $eleve) {
                    $parentPrincipal = $eleve->tuteurs->first();

                    fputcsv($file, [
                        $eleve->matricule,
                        $eleve->nom,
                        $eleve->prenom,
                        $eleve->date_naissance->format('d/m/Y'),
                        $eleve->sexe === 'M' ? 'Masculin' : 'Féminin',
                        $eleve->telephone,
                        $eleve->email,
                        $parentPrincipal ? $parentPrincipal->nom.' '.$parentPrincipal->prenom : $eleve->nom_parent,
                        $parentPrincipal ? $parentPrincipal->telephone : $eleve->telephone_parent,
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        return response()->json(['success' => false, 'message' => 'Format non supporté'], 400);
    }

    /**
     * Obtenir les statistiques générales
     */
    public function getStats()
    {
        $totalEleves = Eleve::count();
        $totalClasses = Classe::where('is_active', true)->count();
        $totalProfesseurs = Professeur::where('is_active', true)->count();

        // Répartition par sexe
        $repartitionSexe = Eleve::select('sexe', DB::raw('count(*) as count'))
            ->groupBy('sexe')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->sexe => $item->count];
            });

        // Répartition par niveau
        $repartitionNiveau = Classe::withCount('eleves')
            ->get()
            ->mapWithKeys(function ($classe) {
                return [$classe->niveau => $classe->eleves_count];
            });

        return response()->json([
            'totalEleves' => $totalEleves,
            'totalClasses' => $totalClasses,
            'totalProfesseurs' => $totalProfesseurs,
            'repartitionSexe' => $repartitionSexe,
            'repartitionNiveau' => $repartitionNiveau,
        ]);
    }

    /**
     * Dashboard du directeur
     */
    public function dashboard(Request $request)
    {
        $anneeScolaire = $request->query('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());
        $toutesAnnees = \App\Models\HistoriqueEleve::distinct()->pluck('annee_scolaire')->toArray();
        if (!in_array(\App\Models\Setting::getCurrentAnneeScolaire(), $toutesAnnees)) {
            $toutesAnnees[] = \App\Models\Setting::getCurrentAnneeScolaire();
        }

        $totalEleves = Eleve::where('statut', 'actif')->count();
        $totalClasses = Classe::where('is_active', true)->count();
        $totalProfesseurs = Professeur::where('is_active', true)->count();

        $derniersEleves = Eleve::with('classe')->orderBy('created_at', 'desc')->limit(5)->get();
        $derniersProfesseurs = Professeur::orderBy('created_at', 'desc')->limit(5)->get();

        $repartitionSexe = [
            'garcons' => Eleve::where('sexe', 'M')->count(),
            'filles' => Eleve::where('sexe', 'F')->count(),
        ];

        // Récupération des données pour les graphiques
        $labels = Classe::where('is_active', true)->pluck('nom');
        $elevesParClasse = Classe::withCount('eleves')->where('is_active', true)->pluck('eleves_count');

        // Statistiques de prise de décision par salle (classe)
        $classesStats = Classe::withCount('eleves')->where('is_active', true)->get();
        $decisionStats = [];

        foreach($classesStats as $classe) {
            $notes = \App\Models\Note::where('classe_id', $classe->id)
                ->where('annee_scolaire', $anneeScolaire)
                ->whereNotNull('moyenne_trimestrielle')
                ->get();
            $moyenne = $notes->avg('moyenne_trimestrielle');
            $passCount = $notes->where('moyenne_trimestrielle', '>=', 10)->count();
            $passRate = $notes->count() > 0 ? round(($passCount / $notes->count()) * 100, 1) : 0;

            $decisionStats[] = [
                'id' => $classe->id,
                'nom' => $classe->nom,
                'effectif' => $classe->eleves_count,
                'moyenne_generale' => $moyenne ? round($moyenne, 2) : 0,
                'taux_reussite' => $passRate,
                'total_notes' => $notes->count()
            ];
        }

        usort($decisionStats, function($a, $b) {
            return $b['taux_reussite'] <=> $a['taux_reussite'];
        });

        // Période d'analyse
        $debutMois = \Carbon\Carbon::now()->startOfMonth();
        $finMois = \Carbon\Carbon::now()->endOfMonth();

        // --- 1. BILAN FINANCIER ---
        // Entrées
        $totalScolarite = Paiement::where('statut', 'success')
            ->where('annee_scolaire', $anneeScolaire)
            ->whereBetween('date_paiement', [$debutMois, $finMois])
            ->sum('montant');
        $totalVentes = \App\Models\Vente::whereBetween('date_vente', [$debutMois, $finMois])
            ->sum('montant_total');
        $totalEntrees = $totalScolarite + $totalVentes;

        // Sorties
        $totalSalaires = \App\Models\Depense::whereBetween('date_depense', [$debutMois, $finMois])
            ->where('categorie', 'salaire')
            ->sum('montant');
        $totalDepensesGenerales = \App\Models\Depense::whereBetween('date_depense', [$debutMois, $finMois])
            ->where('categorie', '!=', 'salaire')
            ->sum('montant');
        $totalSorties = $totalSalaires + $totalDepensesGenerales;
        $soldeNet = $totalEntrees - $totalSorties;

        // --- 2. BILAN INVENTAIRE ---
        $valeurStock = \App\Models\Article::where('type', 'physique')
            ->selectRaw('SUM(stock_actuel * prix_unitaire) as total')
            ->value('total') ?? 0;

        $alertesStock = \App\Models\Article::where('type', 'physique')
            ->whereRaw('stock_actuel <= stock_min')
            ->count();

        // --- 3. INTELLIGENCE (Suggestions de Décisions) ---
        $decisions = [];
        
        // Santé financière
        if ($totalSorties > 0 && $soldeNet < 0) {
            $decisions[] = [
                'type' => 'warning',
                'titre' => 'Déficit Mensuel Détecté',
                'message' => 'Les dépenses ('.number_format($totalSorties, 0, ',', ' ').' FCFA) dépassent les entrées. Il est conseillé de retarder les achats non essentiels ou de relancer le recouvrement des scolarités.',
            ];
        } elseif ($totalEntrees > 0 && ($totalSalaires / $totalEntrees) > 0.6) {
            $decisions[] = [
                'type' => 'warning',
                'titre' => 'Masse Salariale Élevée',
                'message' => 'Les salaires représentent plus de 60% de vos revenus ce mois-ci. Assurez-vous d\'augmenter les recouvrements pour stabiliser la trésorerie.',
            ];
        } else {
             $decisions[] = [
                'type' => 'success',
                'titre' => 'Santé Financière Stable',
                'message' => 'La balance de trésorerie est positive ou maîtrisée. Le recouvrement actuel couvre efficacement les charges d\'exploitation.',
            ];
        }

        // Inventaire
        if ($alertesStock > 0) {
            $decisions[] = [
                'type' => 'error',
                'titre' => 'Ruptures de Stock Imminentes',
                'message' => $alertesStock.' article(s) ont franchi le seuil d\'alerte. Approuvez des bons de commande rapidement pour éviter de bloquer les ventes ou le fonctionnement de l\'école.',
            ];
        }

        return response()->json([
            'success' => true,
            'annee_scolaire_active' => $anneeScolaire,
            'annees_disponibles' => array_values(array_unique($toutesAnnees)),
            'data' => [
                'statistiques' => [
                    'totalEleves' => $totalEleves,
                    'totalClasses' => $totalClasses,
                    'totalProfesseurs' => $totalProfesseurs,
                    'garcons' => $repartitionSexe['garcons'],
                    'filles' => $repartitionSexe['filles'],
                    'labels' => $labels,
                    'elevesParClasse' => $elevesParClasse,
                    'derniersEleves' => $derniersEleves,
                    'derniersProfesseurs' => $derniersProfesseurs,
                    'decision_stats' => $decisionStats,
                ],
                'financier' => [
                    'entrees' => [
                        'scolarite' => $totalScolarite,
                        'ventes' => $totalVentes,
                        'total' => $totalEntrees
                    ],
                    'sorties' => [
                        'salaires' => $totalSalaires,
                        'depenses_generales' => $totalDepensesGenerales,
                        'total' => $totalSorties
                    ],
                    'solde_net' => $soldeNet
                ],
                'inventaire' => [
                    'valeur_totale' => (float) $valeurStock,
                    'alertes_rupture' => $alertesStock,
                ],
                'decisions' => $decisions
            ],
        ]);
    }

    public function gestionNotes(Request $request)
    {
        // Récupérer les filtres
        $filters = [
            'classe_id' => $request->classe_id,
            'eleve_id' => $request->eleve_id,
            'matiere_id' => $request->matiere_id,
            'professeur_id' => $request->professeur_id,
            'trimestre' => $request->trimestre,
        ];

        // Requête de base avec les relations
        $query = Note::with(['eleve', 'classe', 'matiere', 'professeur']);

        // Appliquer les filtres
        if ($filters['classe_id']) {
            $query->where('classe_id', $filters['classe_id']);
        }

        if ($filters['eleve_id']) {
            $query->where('eleve_id', $filters['eleve_id']);
        }

        if ($filters['matiere_id']) {
            $query->where('matiere_id', $filters['matiere_id']);
        }

        if ($filters['professeur_id']) {
            $query->where('professeur_id', $filters['professeur_id']);
        }

        if ($filters['trimestre']) {
            $query->where('trimestre', $filters['trimestre']);
        }

        // Ordonner par classe, élève, matière et trimestre
        $notes = $query->orderBy('classe_id')
            ->orderBy('eleve_id')
            ->orderBy('matiere_id')
            ->orderBy('trimestre')
            ->paginate(20);

        // Données pour les filtres
        $classes = Classe::where('is_active', true)->orderBy('niveau')->orderBy('nom')->get();
        $eleves = Eleve::orderBy('nom')->orderBy('prenom')->get();
        $matieres = Matiere::orderBy('nom')->get();
        $professeurs = Professeur::where('is_active', true)->orderBy('last_name')->get();
        $trimestres = [1, 2, 3];

        // Statistiques
        $stats = [
            'total_notes' => $notes->total(),
            'moyenne_generale' => $query->avg('moyenne_trimestrielle'),
            'notes_par_trimestre' => Note::select('trimestre', \DB::raw('COUNT(*) as count'))
                ->groupBy('trimestre')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'notes' => $notes,
            'classes' => $classes,
            'eleves' => $eleves,
            'matieres' => $matieres,
            'professeurs' => $professeurs,
            'trimestres' => $trimestres,
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }

    /**
     * Export des notes
     */
    public function exportNotes(Request $request)
    {
        $filters = $request->only(['classe_id', 'eleve_id', 'matiere_id', 'professeur_id', 'trimestre']);

        $query = Note::with(['eleve', 'classe', 'matiere', 'professeur']);

        foreach ($filters as $key => $value) {
            if ($value) {
                $query->where($key, $value);
            }
        }

        $notes = $query->orderBy('classe_id')
            ->orderBy('eleve_id')
            ->orderBy('matiere_id')
            ->orderBy('trimestre')
            ->get();

        // Générer le PDF
        $pdf = \PDF::loadView('directeur.notes.export', [
            'notes' => $notes,
            'filters' => $filters,
            'date' => now()->format('d/m/Y'),
        ]);

        return $pdf->download('notes-export-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Statistiques détaillées
     */
    public function statsNotes(Request $request)
    {
        $filters = $request->only(['classe_id', 'matiere_id', 'trimestre']);

        $query = Note::with(['classe', 'matiere']);

        foreach ($filters as $key => $value) {
            if ($value) {
                $query->where($key, $value);
            }
        }

        // Statistiques par classe
        $statsClasse = $query->select('classe_id', \DB::raw('
            COUNT(*) as total_notes,
            AVG(moyenne_trimestrielle) as moyenne_classe,
            MIN(moyenne_trimestrielle) as min_note,
            MAX(moyenne_trimestrielle) as max_note
        '))->groupBy('classe_id')->get();

        // Statistiques par matière
        $statsMatiere = $query->select('matiere_id', \DB::raw('
            COUNT(*) as total_notes,
            AVG(moyenne_trimestrielle) as moyenne_matiere
        '))->groupBy('matiere_id')->get();

        // Répartition des appréciations
        $statsAppreciations = $query->select('commentaire', \DB::raw('COUNT(*) as count'))
            ->groupBy('commentaire')
            ->orderBy('count', 'desc')
            ->get();

        $classes = Classe::where('is_active', true)->orderBy('niveau')->orderBy('nom')->get();
        $matieres = Matiere::orderBy('nom')->get();
        $trimestres = [1, 2, 3];

        return response()->json([
            'success' => true,
            'statsClasse' => $statsClasse,
            'statsMatiere' => $statsMatiere,
            'statsAppreciations' => $statsAppreciations,
            'classes' => $classes,
            'matieres' => $matieres,
            'trimestres' => $trimestres,
            'filters' => $filters,
        ]);
    }

    /**
     * Détails d'une note spécifique
     */
    public function detailNote(Note $note)
    {
        $note->load(['eleve', 'classe', 'matiere', 'professeur']);

        return response()->json([
            'success' => true,
            'note' => $note,
        ]);
    }

    public function detailProfesseur(Professeur $professeur, Request $request)
    {
        // Filtres pour les cahiers de texte
        $filters = [
            'date_debut' => $request->date_debut,
            'date_fin' => $request->date_fin,
            'classe_id' => $request->classe_id,
        ];

        // Charger les relations
        $professeur->load([
            'classesPrincipales',
            'matieresEnseignees',
            'notes' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(10);
            },
            'classes' => function ($query) {
                $query->withCount('eleves');
            },
        ]);

        // Cahiers de texte du professeur
        $cahiersQuery = CahierTexte::with('classe')
            ->where('professeur_id', $professeur->id);

        if ($filters['date_debut']) {
            $cahiersQuery->where('date_cours', '>=', $filters['date_debut']);
        }

        if ($filters['date_fin']) {
            $cahiersQuery->where('date_cours', '<=', $filters['date_fin']);
        }

        if ($filters['classe_id']) {
            $cahiersQuery->where('classe_id', $filters['classe_id']);
        }

        $cahiers = $cahiersQuery->orderBy('date_cours', 'desc')
            ->orderBy('heure_debut', 'desc')
            ->paginate(15, ['*'], 'cahiers_page');

        // Statistiques du professeur
        $stats = [
            'total_notes' => $professeur->notes->count(),
            'moyenne_notes' => $professeur->notes->avg('moyenne_trimestrielle'),
            'classes_principales' => $professeur->classesPrincipales->count(),
            'matieres_enseignees' => $professeur->matieresEnseignees->count(),
            'total_eleves' => $professeur->classes->sum('eleves_count'),
            'total_cahiers' => CahierTexte::where('professeur_id', $professeur->id)->count(),
            'cahiers_7j' => CahierTexte::where('professeur_id', $professeur->id)
                ->where('date_cours', '>=', Carbon::now()->subDays(7))
                ->count(),
        ];

        // Classes pour le filtre
        $classes = $professeur->classes;

        return response()->json([
            'success' => true,
            'professeur' => $professeur,
            'stats' => $stats,
            'cahiers' => $cahiers,
            'filters' => $filters,
            'classes' => $classes,
        ]);
    }

    /**
     * Activer/Désactiver un professeur
     */
    public function toggleProfesseur(Professeur $professeur)
    {
        $professeur->update([
            'is_active' => ! $professeur->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Statut du professeur mis à jour avec succès.',
            'is_active' => $professeur->is_active,
        ]);
    }

    /**
     * Exporter la liste des professeurs
     */
    public function exportProfesseurs(Request $request)
    {
        $filters = $request->only(['is_active', 'matiere', 'search']);

        $query = Professeur::with(['classesPrincipales', 'matieresEnseignees']);

        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                if ($key === 'search') {
                    $query->where(function ($q) use ($value) {
                        $q->where('last_name', 'like', '%'.$value.'%')
                            ->orWhere('first_name', 'like', '%'.$value.'%')
                            ->orWhere('email', 'like', '%'.$value.'%')
                            ->orWhere('personal_code', 'like', '%'.$value.'%');
                    });
                } else {
                    $query->where($key, $value);
                }
            }
        }

        $professeurs = $query->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // Générer le QR Code d'authentification
        $qrData = json_encode([
            'type' => 'Liste des Professeurs',
            'effectif' => $professeurs->count(),
            'date' => now()->format('Y-m-d H:i:s'),
            'certifie_par' => 'Notre Dame Pro'
        ]);
        
        $qrResult = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($qrData)
            ->size(100)
            ->margin(0)
            ->build();
            
        $qrCodeImage = base64_encode($qrResult->getString());

        // Générer le PDF
        $pdf = \PDF::loadView('directeur.professeurs.export', [
            'professeurs' => $professeurs,
            'filters' => $filters,
            'date' => now()->format('d/m/Y'),
            'qrCodeImage' => $qrCodeImage
        ]);

        return $pdf->download('professeurs-export-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Voir les détails d'un cahier de texte
     */
    public function detailCahierTexte(CahierTexte $cahier)
    {
        $cahier->load(['professeur', 'classe']);

        return response()->json([
            'success' => true,
            'cahier' => $cahier,
        ]);
    }

    public function cloturerAnneeScolaire(Request $request)
    {
        $request->validate([
            'nouvelle_annee' => 'required|string|max:20',
        ]);

        try {
            DB::beginTransaction();

            $anneeCourante = Setting::getCurrentAnneeScolaire();

            // 1. Récupérer tous les élèves actifs (statut = 'actif')
            $eleves = Eleve::where('statut', 'actif')->get();

            $nbPromus = 0;
            $nbRedoublants = 0;

            foreach ($eleves as $eleve) {
                // 2. Calculer la moyenne annuelle à partir des notes de l'année courante
                // Note: since annee_scolaire is newly added and defaults to '2025-2026', we filter by it.
                $notes = Note::where('eleve_id', $eleve->id)
                    ->where('annee_scolaire', $anneeCourante)
                    ->whereNotNull('moyenne_trimestrielle')
                    ->get();
                
                // Average of the trimestres. A student typically has notes in 3 trimestres.
                // To get the true annual average, we can average the trimester averages.
                // Alternatively, we group by matiere and find the average per matiere, but 
                // the prompt says: "(Trimestre 1 + Trimestre 2 + Trimestre 3) / 3".
                // In DB, notes are stored per matiere and trimestre.
                // So average of all `moyenne_trimestrielle` for the student is mathematically the same if weighted equally.
                $moyenneAnnuelle = $notes->avg('moyenne_trimestrielle');
                
                $decision = 'Redouble';
                if ($moyenneAnnuelle !== null && $moyenneAnnuelle >= 10) {
                    $decision = 'Admis';
                    $nbPromus++;
                } else {
                    $nbRedoublants++;
                }

                // 3. Sauvegarder dans l'historique
                HistoriqueEleve::create([
                    'eleve_id' => $eleve->id,
                    'classe_id' => $eleve->classe_id,
                    'annee_scolaire' => $anneeCourante,
                    'moyenne_annuelle' => $moyenneAnnuelle,
                    'decision' => $decision,
                    'commentaires' => "Clôture automatique",
                ]);

                // 4. Mettre à jour le statut de l'élève
                if ($decision === 'Admis') {
                    $eleve->update([
                        'statut' => 'en_attente',
                        // We keep classe_id to know where they came from until assigned
                    ]);
                }
            }

            // 5. Passer à la nouvelle année scolaire
            Setting::setCurrentAnneeScolaire($request->nouvelle_annee);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "L'année scolaire a été clôturée avec succès.",
                'stats' => [
                    'eleves_traites' => $eleves->count(),
                    'promus' => $nbPromus,
                    'redoublants' => $nbRedoublants,
                    'ancienne_annee' => $anneeCourante,
                    'nouvelle_annee' => $request->nouvelle_annee
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la clôture : ' . $e->getMessage()
            ], 500);
        }
    }
}
