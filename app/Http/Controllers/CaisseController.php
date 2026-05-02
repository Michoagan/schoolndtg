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

class CaisseController extends Controller
{
    /**
     * Tableau de bord Caisse (Résumé du jour).
     */
    public function dashboard(Request $request)
    {
        $date = $request->input('date', now()->toDateString());
        
        $anneeScolaire = $request->input('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());
        $toutesAnnees = \App\Models\HistoriqueEleve::distinct()->pluck('annee_scolaire')->toArray();
        if (!in_array(\App\Models\Setting::getCurrentAnneeScolaire(), $toutesAnnees)) {
            $toutesAnnees[] = \App\Models\Setting::getCurrentAnneeScolaire();
        }

        // Contributions (Paiements scolarité validés du jour)
        $totalScolarite = Paiement::where('statut', 'success')
            ->whereDate('date_paiement', $date)
            ->when($request->has('annee_scolaire'), function($q) use ($anneeScolaire) {
                return $q->where('annee_scolaire', $anneeScolaire);
            })
            ->sum('montant');

        // Ventes (Autres recettes du jour)
        $totalVentes = Vente::whereDate('date_vente', $date)
            ->sum('montant_total');

        $totalEntrees = $totalScolarite + $totalVentes;

        return response()->json([
            'annee_scolaire_active' => $anneeScolaire,
            'annees_disponibles' => array_values(array_unique($toutesAnnees)),
            'date' => $date,
            'entrees' => [
                'scolarite' => $totalScolarite,
                'ventes' => $totalVentes,
                'total' => $totalEntrees
            ]
        ]);
    }

    /**
     * Liste de tous les paiements (Scolarité) pour la Caisse (Année en cours).
     */
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
            'receipt_url' => url('/api/direction/caisse/paiements/' . $transaction->id . '/receipt') // Changed to caisse
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
        
        $filename = 'recu_paiement_' . $paiement->reference . '.pdf';

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"$filename\"");
    }

    /**
     * Get QR Code for a Paiement (For React PDF generation)
     */
    public function getPaiementQrCode(Paiement $paiement)
    {
        $paiement->load(['eleve']);
        
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
            ->size(200) // Slightly larger for better clarity on client side
            ->margin(10)
            ->roundBlockSizeMode(\Endroid\QrCode\RoundBlockSizeMode::Margin)
            ->foregroundColor(new \Endroid\QrCode\Color\Color(0, 0, 0))
            ->backgroundColor(new \Endroid\QrCode\Color\Color(255, 255, 255))
            ->build();

        return response()->json([
            'success' => true,
            'qrCodeBase64' => base64_encode($result->getString())
        ]);
    }

    /**
     * Enregistrer une Vente (Autre recette : Tenue, Cantine, Vente directe).
     */
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
     * Download the PDF receipt for a specific vente.
     */
    public function downloadVenteReceipt(Vente $vente)
    {
        $vente->load(['eleve', 'eleve.classe', 'auteur', 'lignes.article']);
        
        // Generate QR code locally via endroid/qr-code
        $qrData = [
            'vente_id' => $vente->id,
            'reference' => $vente->reference,
            'client' => $vente->eleve ? $vente->eleve->nom . ' ' . $vente->eleve->prenom : ($vente->nom_client ?? 'Client Anonyme'),
            'montant' => $vente->montant_total,
            'date' => $vente->date_vente ? \Carbon\Carbon::parse($vente->date_vente)->format('d/m/Y H:i') : null,
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

        $pdf = Pdf::loadView('pdf.vente_receipt', [
            'vente' => $vente,
            'qrCodeImage' => $qrCodeImage,
            'date_generation' => now()->format('d/m/Y H:i:s')
        ]);
        
        $filename = 'recu_vente_' . $vente->reference . '.pdf';

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"$filename\"");
    }

    /**
     * Get QR Code for a Vente (For React PDF generation)
     */
    public function getVenteQrCode(Vente $vente)
    {
        $vente->load(['eleve']);
        
        $qrData = [
            'vente_id' => $vente->id,
            'reference' => $vente->reference,
            'client' => $vente->eleve ? $vente->eleve->nom . ' ' . $vente->eleve->prenom : ($vente->nom_client ?? 'Client Anonyme'),
            'montant' => $vente->montant_total,
            'date' => $vente->date_vente ? \Carbon\Carbon::parse($vente->date_vente)->format('d/m/Y H:i') : null,
            'statut' => 'Payé'
        ];

        $qrText = json_encode($qrData);

        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($qrText)
            ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
            ->errorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Low)
            ->size(200)
            ->margin(10)
            ->roundBlockSizeMode(\Endroid\QrCode\RoundBlockSizeMode::Margin)
            ->foregroundColor(new \Endroid\QrCode\Color\Color(0, 0, 0))
            ->backgroundColor(new \Endroid\QrCode\Color\Color(255, 255, 255))
            ->build();

        return response()->json([
            'success' => true,
            'qrCodeBase64' => base64_encode($result->getString())
        ]);
    }
}
