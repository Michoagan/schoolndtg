<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\Paiement;
use App\Models\Vente;
use App\Models\Article;
use App\Models\LigneVente;
use App\Models\MouvementStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceiptMail;

class ComptabiliteController extends Controller
{
    /**
     * Tableau de bord comptable (Résumé).
     */
    public function dashboard(Request $request)
    {
        // Filtre par date (Mois en cours par défaut)
        $dateStart = $request->input('start_date', now()->startOfMonth()->toDateString());
        $dateEnd = $request->input('end_date', now()->endOfMonth()->toDateString());
        
        $anneeScolaire = $request->input('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());
        $toutesAnnees = \App\Models\HistoriqueEleve::distinct()->pluck('annee_scolaire')->toArray();
        if (!in_array(\App\Models\Setting::getCurrentAnneeScolaire(), $toutesAnnees)) {
            $toutesAnnees[] = \App\Models\Setting::getCurrentAnneeScolaire();
        }

        // --- 1. BILAN FINANCIER (Trésorerie) ---
        
        // Entrées
        $totalScolarite = Paiement::where('statut', 'success')
            ->whereBetween('date_paiement', [$dateStart, $dateEnd])
            ->when($request->has('annee_scolaire'), function($q) use ($anneeScolaire) {
                return $q->where('annee_scolaire', $anneeScolaire);
            })
            ->sum('montant');

        $totalVentes = Vente::whereBetween('date_vente', [$dateStart, $dateEnd])
            ->sum('montant_total');

        $totalEntrees = $totalScolarite + $totalVentes;

        // Sorties
        // Assuming Salaires are already recorded as Depense in this system (as per payerSalaire method)
        // To be precise on the analytical breakdown, we will sum them separately if needed, 
        // but since payerSalaire creates a Depense with categorie='salaire', we can filter by category!
        $totalSalaires = Depense::whereBetween('date_depense', [$dateStart, $dateEnd])
            ->where('categorie', 'salaire')
            ->sum('montant');

        $totalDepensesGenerales = Depense::whereBetween('date_depense', [$dateStart, $dateEnd])
            ->where('categorie', '!=', 'salaire')
            ->sum('montant');

        $totalSorties = $totalSalaires + $totalDepensesGenerales;
        
        $soldeNet = $totalEntrees - $totalSorties;

        // --- 2. BILAN INVENTAIRE (Patrimoine) ---
        
        // Valeur Totale du Stock Physique
        $valeurStock = Article::where('type', 'physique')
            ->selectRaw('SUM(stock_actuel * prix_unitaire) as total')
            ->value('total') ?? 0;

        // Nombre d'articles en alerte (stock <= stock_min)
        $alertesStock = Article::where('type', 'physique')
            ->whereRaw('stock_actuel <= stock_min')
            ->count();

        // Mouvements du mois
        $mouvementsEntrees = MouvementStock::whereBetween('created_at', [$dateStart, clone(now()->parse($dateEnd))->endOfDay()])
            ->whereIn('type', ['entree', 'correction']) // correction is often positive
            ->count();

        $mouvementsSorties = MouvementStock::whereBetween('created_at', [$dateStart, clone(now()->parse($dateEnd))->endOfDay()])
            ->where('type', 'vente')
            ->count();

        return response()->json([
            'annee_scolaire_active' => $anneeScolaire,
            'annees_disponibles' => array_values(array_unique($toutesAnnees)),
            'period' => [
                'start' => $dateStart,
                'end' => $dateEnd
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
                'mouvements' => [
                    'entrees' => $mouvementsEntrees,
                    'sorties' => $mouvementsSorties
                ]
            ]
        ]);
    }

    /**
     * Liste des dépenses
     */
    public function index()
    {
        $depenses = Depense::with('auteur')->latest('date_depense')->get();
        return response()->json($depenses);
    }

    // Méthodes de paiements déplacées vers CaisseController.
    public function indexPaiements()
    {
        $paiements = Paiement::with(['eleve.classe', 'contribution'])
            ->where('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire())
            ->latest('date_paiement')
            ->get();
            
        return response()->json($paiements);
    }

    /**
     * Enregistrer manuellement un Paiement (Scolarité).
     */
    public function storePaiement(Request $request)
    {
        $request->validate([
            'montant' => 'required|numeric|min:1',
            'methode' => 'required|string',
            'eleve_id' => 'required|exists:eleves,id',
        ]);

        $eleve = \App\Models\Eleve::with('classe')->findOrFail($request->eleve_id);
        if (!$eleve->classe) {
            return response()->json(['success' => false, 'message' => 'L\'élève n\'a pas de classe.'], 400);
        }

        $contribution = $eleve->classe->contributionActive();
        if (!$contribution) {
            $contribution = \App\Models\Contribution::firstOrCreate([
                'classe_id' => $eleve->classe_id,
                'annee_scolaire' => \App\Models\Contribution::getAnneeScolaireCourante(),
                'type' => \App\Models\Contribution::TYPE_SCOLARITE,
            ], [
                'montant_total' => $eleve->classe->cout_contribution ?? 50000,
                'montant_paye' => 0,
                'description' => 'Scolarité générée automatiquement',
                'est_obligatoire' => true
            ]);
        }

        $transaction = Paiement::create([
            'reference' => 'PYR-'.date('Y').'-'.\Illuminate\Support\Str::random(6),
            'eleve_id' => $request->eleve_id,
            'contribution_id' => $contribution->id,
            'montant' => $request->montant,
            'methode' => $request->methode,
            'statut' => 'success', // Manual payment is immediately successful
            'date_paiement' => now(),
        ]);

        $transaction->load(['eleve.classe', 'contribution']);

        // Generate the PDF in memory
        $pdf = Pdf::loadView('pdf.receipt', ['paiement' => $transaction]);
        $pdfContent = $pdf->output();

        // Send email to parent if email exists
        if (!empty($eleve->email)) {
            try {
                Mail::to($eleve->email)->send(new PaymentReceiptMail($transaction, $pdfContent));
            } catch (\Exception $e) {
                \Log::error('Erreur lors de l\'envoi du reçu par email: ' . $e->getMessage());
                // Non-blocking: we still return success for the payment even if email fails
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Paiement enregistré avec succès.',
            'paiement' => $transaction,
            'receipt_url' => url('/api/direction/comptabilite/paiements/' . $transaction->id . '/receipt') // Optional direct URL
        ]);
    }

    /**
     * Download the PDF receipt for a specific payment.
     */
    public function downloadReceipt(Paiement $paiement)
    {
        $paiement->load(['eleve', 'eleve.classe', 'eleve.tuteurs', 'contribution']);
        
        // Generate QR code locally via endroid/qr-code
        $qrData = [
            'recu_id' => $paiement->id,
            'reference' => $paiement->reference_externe ?? $paiement->reference,
            'eleve' => $paiement->eleve->nom . ' ' . $paiement->eleve->prenom,
            'montant' => $paiement->montant,
            'date' => $paiement->date_paiement ? \Carbon\Carbon::parse($paiement->date_paiement)->format('d/m/Y H:i') : null,
            'statut' => 'Payé'
        ];

        $qrText = json_encode($qrData);

        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($qrText)
            ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
            ->errorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Low)
            ->size(100)
            ->margin(10)
            ->roundBlockSizeMode(\Endroid\QrCode\RoundBlockSizeMode::Margin)
            ->foregroundColor(new \Endroid\QrCode\Color\Color(0, 0, 0))
            ->backgroundColor(new \Endroid\QrCode\Color\Color(255, 255, 255))
            ->build();

        $qrCodeImage = base64_encode($result->getString());

        $pdf = Pdf::loadView('pdf.receipt', [
            'paiement' => $paiement,
            'qrCodeImage' => $qrCodeImage,
            'date_generation' => now()->format('d/m/Y H:i:s')
        ]);
        
        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"$filename\"");
    }

    /**
     * Enregistrer une Dépense (Sortie).
     */
    public function storeDepense(Request $request)
    {
        $request->validate([
            'motif' => 'required|string',
            'montant' => 'required|numeric|min:0',
            'categorie' => 'required|in:salaire,achat_materiel,tache,autre',
            'date_depense' => 'required|date',
        ]);

        $depense = Depense::create([
            'motif' => $request->motif,
            'montant' => $request->montant,
            'categorie' => $request->categorie,
            'date_depense' => $request->date_depense,
            'description' => $request->description,
            'auteur_id' => auth()->id(),
            // Gestion bénéficiaire à ajouter si besoin (ex: ID prof)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dépense enregistrée.',
            'depense' => $depense
        ]);
    }

    // Méthode de vente déplacée vers CaisseController.
    public function storeVente(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.article_id' => 'required|exists:articles,id',
            'items.*.quantite' => 'required|integer|min:1',
            'eleve_id' => 'nullable|exists:eleves,id',
            'nom_client' => 'nullable|string',
        ]);

        // Calcul total et validation stock
        return DB::transaction(function () use ($request) {
            $total = 0;
            $itemsToProcess = [];

            foreach ($request->items as $itemData) {
                $article = Article::find($itemData['article_id']);
                
                // Vérif stock physique
                if ($article->type === 'physique' && $article->stock_actuel < $itemData['quantite']) {
                    throw new \Exception("Stock insuffisant pour " . $article->designation);
                }

                $prix = $article->prix_unitaire;
                $sousTotal = $prix * $itemData['quantite'];
                $total += $sousTotal;

                $itemsToProcess[] = [
                    'article' => $article,
                    'quantite' => $itemData['quantite'],
                    'prix' => $prix,
                    'sous_total' => $sousTotal
                ];
            }

            // Création Vente
            $vente = Vente::create([
                'reference' => 'VNT-' . date('Ymd') . '-' . Str::upper(Str::random(4)),
                'eleve_id' => $request->eleve_id,
                'nom_client' => $request->nom_client ?? ($request->eleve_id ? null : 'Client Anonyme'),
                'montant_total' => $total,
                'date_vente' => now(),
                'auteur_id' => auth()->id(),
            ]);

            // Création Lignes et Mvt Stock
            foreach ($itemsToProcess as $item) {
                LigneVente::create([
                    'vente_id' => $vente->id,
                    'article_id' => $item['article']->id,
                    'quantite' => $item['quantite'],
                    'prix_unitaire' => $item['prix'],
                    'sous_total' => $item['sous_total'],
                ]);

                // Sortie de Stock si physique
                if ($item['article']->type === 'physique') {
                    $oldStock = $item['article']->stock_actuel;
                    $newStock = $oldStock - $item['quantite'];
                    
                    $item['article']->update(['stock_actuel' => $newStock]);

                    MouvementStock::create([
                        'article_id' => $item['article']->id,
                        'type' => 'vente',
                        'quantite' => $item['quantite'],
                        'stock_precedent' => $oldStock,
                        'nouveau_stock' => $newStock,
                        'motif' => 'Vente ' . $vente->reference,
                        'source_type' => Vente::class,
                        'source_id' => $vente->id,
                        'auteur_id' => auth()->id(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Vente enregistrée avec succès.',
                'vente' => $vente->load('lignes.article')
            ]);

        }); // End Transaction
    }

    /**
     * Liste des salaires d'un mois et d'une annee
     */
    public function salaires(Request $request)
    {
        $mois = $request->query('mois', now()->month);
        $annee = $request->query('annee', now()->year);

        $salaires = \App\Models\Salaire::with(['professeur', 'directionUser'])
            ->where('mois', $mois)
            ->where('annee', $annee)
            ->get();

        return response()->json($salaires);
    }

    /**
     * Generer les salaires pour un mois/annee specifique.
     * Calcule le total d'heures base sur l'emploi du temps ou presence pour les profs, et le salaire de base pour la direction.
     */
    public function generateSalaires(Request $request)
    {
        $request->validate([
            'mois' => 'required|integer|min:1|max:12',
            'annee' => 'required|integer|min:2020',
        ]);

        $mois = $request->mois;
        $annee = $request->annee;

        $generated = 0;

        // 1. Générer pour les Professeurs
        $professeurs = \App\Models\Professeur::all();
        foreach ($professeurs as $prof) {
            $exists = \App\Models\Salaire::where('professeur_id', $prof->id)
                ->where('mois', $mois)
                ->where('annee', $annee)
                ->exists();

            if ($exists) continue;

            $heures = 120; // Example static 120 hours
            $taux = $prof->taux_horaire ?? 5000;
            $base = $heures * $taux;

            \App\Models\Salaire::create([
                'professeur_id' => $prof->id,
                'mois' => $mois,
                'annee' => $annee,
                'heures_travaillees' => $heures,
                'taux_horaire' => $taux,
                'montant_base' => $base,
                'primes' => 0,
                'retenues' => 0,
                'net_a_payer' => $base,
                'statut' => 'en_attente'
            ]);
            $generated++;
        }

        // 2. Générer pour le Personnel de Direction et Agents
        $personnel = \App\Models\Direction::where('is_active', true)->get();
        foreach ($personnel as $agent) {
            $exists = \App\Models\Salaire::where('direction_user_id', $agent->id)
                ->where('mois', $mois)
                ->where('annee', $annee)
                ->exists();

            if ($exists) continue;

            $base = $agent->salaire_base ?? 0;

            \App\Models\Salaire::create([
                'direction_user_id' => $agent->id,
                'mois' => $mois,
                'annee' => $annee,
                'heures_travaillees' => 0, 
                'taux_horaire' => 0,
                'montant_base' => $base,
                'primes' => 0,
                'retenues' => 0,
                'net_a_payer' => $base,
                'statut' => 'en_attente' // L'édition des primes se fera avant paiement
            ]);
            $generated++;
        }

        return response()->json([
            'success' => true,
            'message' => "$generated fiche(s) de salaire générée(s) pour $mois/$annee."
        ]);
    }

    /**
     * Mettre à jour un salaire (primes, retenues)
     */
    public function updateSalaire(Request $request, $id)
    {
        $request->validate([
            'primes' => 'required|numeric|min:0',
            'retenues' => 'required|numeric|min:0',
        ]);

        $salaire = \App\Models\Salaire::findOrFail($id);

        if ($salaire->statut === 'paye') {
            return response()->json(['success' => false, 'message' => 'Impossible de modifier un salaire déjà payé.'], 400);
        }

        $primes = $request->primes;
        $retenues = $request->retenues;
        $net = $salaire->montant_base + $primes - $retenues;

        $salaire->update([
            'primes' => $primes,
            'retenues' => $retenues,
            'net_a_payer' => $net
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Salaire mis à jour avec succès.',
            'salaire' => $salaire
        ]);
    }

    /**
     * Marquer un salaire comme paye
     */
    public function payerSalaire($id)
    {
        $salaire = \App\Models\Salaire::findOrFail($id);
        
        if ($salaire->statut === 'paye') {
            return response()->json(['success' => false, 'message' => 'Déjà payé.'], 400);
        }

        $isProf = $salaire->professeur_id !== null;
        $employe = $isProf ? $salaire->professeur : $salaire->directionUser;
        $nom = $employe ? $employe->first_name . ' ' . $employe->last_name : 'Anonyme';

        // Creer automatiquement la depense correspondante
        Depense::create([
            'motif' => 'Paiement Salaire - ' . $nom . ' (' . $salaire->mois . '/' . $salaire->annee . ')',
            'montant' => $salaire->net_a_payer,
            'categorie' => 'salaire',
            'date_depense' => now(),
            'auteur_id' => auth()->id(),
        ]);

        $salaire->update([
            'statut' => 'paye',
            'date_paiement' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Salaire payé avec succès et Dépense enregistrée.'
        ]);
    }

    /**
     * Download the PDF Payslip.
     */
    public function downloadFichePaie($id)
    {
        $salaire = \App\Models\Salaire::with(['professeur', 'directionUser'])->findOrFail($id);

        $pdf = Pdf::loadView('pdf.fiche_paie', [
            'salaire' => $salaire
        ]);
        
        $employe = $salaire->professeur_id !== null ? $salaire->professeur : $salaire->directionUser;
        $nom = $employe ? str_replace(' ', '_', $employe->last_name . '_' . $employe->first_name) : 'Anonyme';
        $filename = "fiche_paie_{$salaire->mois}_{$salaire->annee}_{$nom}.pdf";

        return $pdf->download($filename);
    }
}
