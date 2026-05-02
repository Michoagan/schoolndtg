<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Paiement;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceiptMail;


class PaiementController extends Controller
{
    public function index(Request $request)
    {
        $parent = \Illuminate\Support\Facades\Auth::user();

        if (! $parent instanceof \App\Models\Tuteur) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $eleves = $parent->eleves;

        $eleve = null;
        $classe = null;
        $contribution = null;
        $paiements = collect();

        if ($request->has('eleve_id')) {
            $eleve = $eleves->where('id', $request->eleve_id)->first();

            if ($eleve) {
                $classe = $eleve->classe;
                if ($classe) {
                    // Récupération du coût depuis la classe
                    $contribution = $classe->cout_contribution;
                    $paiements = Paiement::where('eleve_id', $eleve->id)->get();
                }
            }
        }

        $paiementEnLigneActif = \App\Models\Setting::where('key', 'paiement_en_ligne_actif')->value('value') ?? 'true';
        $paiementEnLigneActif = filter_var($paiementEnLigneActif, FILTER_VALIDATE_BOOLEAN);

        return response()->json([
            'success' => true,
            'parent' => $parent,
            'eleves' => $eleves,
            'eleve' => $eleve,
            'classe' => $classe,
            'contribution' => $contribution,
            'paiements' => $paiements,
            'paiement_en_ligne_actif' => $paiementEnLigneActif,
        ]);
    }

    public function processPayment(Request $request)
    {
        $paiementEnLigneActif = \App\Models\Setting::where('key', 'paiement_en_ligne_actif')->value('value') ?? 'true';
        $paiementEnLigneActif = filter_var($paiementEnLigneActif, FILTER_VALIDATE_BOOLEAN);

        if (!$paiementEnLigneActif) {
            return response()->json(['success' => false, 'message' => 'Les paiements en ligne sont temporairement désactivés par la direction.'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'required|in:kkiapay,fedapay',
            'eleve_id' => 'required|exists:eleves,id',
            'montant_total' => 'required|numeric',
        ]);

        // SÉCURITÉ [HIGH-1]: Vérifier que l'élève appartient bien au parent authentifié (protection IDOR)
        $parent = \Illuminate\Support\Facades\Auth::user();
        if (!$parent instanceof \App\Models\Tuteur) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $eleve = $parent->eleves()->findOrFail($request->eleve_id); // Lève un 404 si l'élève n'appartient pas à ce parent

        $totalPaye = Paiement::where('eleve_id', $eleve->id)->where('statut', 'success')->sum('montant');
        $soldeRestant = $request->montant_total - $totalPaye;

        // Vérifier que le montant ne dépasse pas le solde restant
        if ($request->amount > $soldeRestant) {
            return response()->json(['success' => false, 'message' => 'Le montant saisi dépasse le solde restant.'], 400);
        }

        // Trouver ou créer la contribution active pour la classe
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

        // Créer une transaction en attente
        $transaction = Paiement::create([
            'reference' => 'PYR-'.date('Y').'-'.Str::random(6),
            'eleve_id' => $request->eleve_id,
            'contribution_id' => $contribution->id,
            'montant' => $request->amount,
            'methode' => $request->payment_method,
            'statut' => 'pending',
            'date_paiement' => now(),
        ]);

        // Rediriger selon la méthode de paiement
        if ($request->payment_method === 'kkiapay') {
            return $this->processKkiaPay($transaction);
        } else {
            return $this->processFedapay($transaction);
        }
    }

    private function processKkiaPay($transaction)
    {
        // SÉCURITÉ: Jamais de clé hardcodée - on échoue de manière sûr si la variable est absente
        $apiKey = env('KKIAPAY_PUBLIC_KEY');
        if (!$apiKey) {
            Log::critical('KKIAPAY_PUBLIC_KEY manquante dans .env');
            return response()->json(['success' => false, 'message' => 'Configuration de paiement non disponible.'], 500);
        }
        $callbackUrl = route('parent.payment-callback', ['method' => 'kkiapay']);

        // Stocker l'ID de transaction en session pour la vérification après paiement
        session(['kkiapay_transaction_id' => $transaction->id]);

        // Retourner l'URL de paiement pour que le frontend lance la page
        return response()->json([
            'success' => true,
            'payment_url' => route('kkiapay.checkout', ['transaction_id' => $transaction->id]),
            'message' => 'Initialisation KkiaPay réussie',
        ]);
    }

    public function kkiapayCheckout(Request $request)
    {
        $transactionId = $request->query('transaction_id');
        $transaction = Paiement::findOrFail($transactionId);

        // SÉCURITÉ: Jamais de clé hardcodée
        $apiKey = env('KKIAPAY_PUBLIC_KEY');
        if (!$apiKey) {
            abort(500, 'Configuration de paiement non disponible.');
        }
        $callbackUrl = route('parent.payment-callback', ['method' => 'kkiapay', 'local_id' => $transaction->id]);

        return view('kkiapay_checkout', [
            'montant' => $transaction->montant,
            'callbackUrl' => $callbackUrl,
            'transactionId' => $transaction->id,
            'apiKey' => $apiKey,
        ]);
    }

    private function processFedapay($transaction)
    {
        try {
            // Configuration de Fedapay
            \FedaPay\FedaPay::setApiKey(env('FEDAPAY_SECRET_KEY'));
            \FedaPay\FedaPay::setEnvironment(env('FEDAPAY_ENVIRONMENT', 'sandbox'));

            $parent = $transaction->eleve->tuteurs->first();
            
            // Créer une transaction Fedapay
            $fedapayTransaction = \FedaPay\Transaction::create([
                'description' => 'Paiement contribution scolaire - '.$transaction->eleve->prenom.' '.$transaction->eleve->nom,
                'amount' => $transaction->montant,
                'currency' => ['iso' => 'XOF'],
                'callback_url' => route('parent.payment-callback', ['method' => 'fedapay']),
                'customer' => [
                    'firstname' => $parent ? $parent->prenom : $transaction->eleve->prenom,
                    'lastname' => $parent ? $parent->nom : $transaction->eleve->nom,
                    'email' => $parent ? $parent->email : 'contact@ecole.com',
                    'phone_number' => $parent ? $parent->telephone : $transaction->eleve->telephone_parent,
                ],
            ]);

            // Mettre à jour la transaction avec la référence Fedapay
            $transaction->update(['reference_externe' => $fedapayTransaction->id]);

            // Retourner l'URL de paiement pour redirection côté frontend
            return response()->json([
                'success' => true,
                'payment_url' => $fedapayTransaction->generateToken()->url,
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            // Gérer l'erreur
            $transaction->update(['statut' => 'failed', 'erreur' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initialisation du paiement: '.$e->getMessage(),
            ], 500);
        }
    }

    public function handleCallback(Request $request, $method)
    {
        if ($method === 'kkiapay') {
            return $this->handleKkiaPayCallback($request);
        } elseif ($method === 'fedapay') {
            return $this->handleFedapayCallback($request);
        }

        return response()->json(['success' => false, 'message' => 'Méthode de paiement non reconnue.'], 400);
    }

    private function handleKkiaPayCallback(Request $request)
    {
        $transactionId = $request->input('transaction_id');
        $localTransactionId = $request->input('local_id');

        $transaction = Paiement::find($localTransactionId);

        if (! $transaction) {
            return response()->json(['success' => false, 'message' => 'Transaction non trouvée.'], 404);
        }

        if (!$transactionId) {
             return response()->json(['success' => false, 'message' => 'Transaction ID Kkiapay manquant.'], 400);
        }

        // Vérification via l'API Kkiapay
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key' => env('KKIAPAY_PUBLIC_KEY'),
            'x-secret-key' => env('KKIAPAY_SECRET_KEY'),
            'x-private-key' => env('KKIAPAY_PRIVATE_KEY'),
            'Accept' => 'application/json'
        ])->post('https://api.kkiapay.me/api/v1/transactions/status', [
            'transactionId' => $transactionId
        ]);

        if ($response->successful() && $response->json('status') === 'SUCCESS') {
            // Mettre à jour le statut de la transaction
            $transaction->update([
                'statut' => 'success',
                'reference_externe' => $transactionId,
                'date_paiement' => now(),
            ]);
            
            $this->sendReceiptEmail($transaction);

            return response()->json([
                'success' => true,
                'message' => 'Paiement effectué avec succès!',
                'receipt_id' => $transaction->id,
            ]);
        } else {
            $transaction->update(['statut' => 'failed']);
            return response()->json(['success' => false, 'message' => 'Le paiement a échoué ou n\'est pas finalisé.'], 400);
        }
    }

    private function handleFedapayCallback(Request $request)
    {
        $transactionId = $request->input('transaction_id');

        if (! $transactionId) {
            return response()->json(['success' => false, 'message' => 'Transaction ID manquant.'], 400);
        }

        try {
            // Récupérer la transaction Fedapay
            \FedaPay\FedaPay::setApiKey(env('FEDAPAY_SECRET_KEY'));
            $fedapayTransaction = \FedaPay\Transaction::retrieve($transactionId);

            // Trouver la transaction dans notre base
            $transaction = Paiement::where('reference_externe', $transactionId)->first();

            if (! $transaction) {
                return response()->json(['success' => false, 'message' => 'Transaction non trouvée.'], 404);
            }

            if ($fedapayTransaction->status === 'approved') {
                $transaction->update([
                    'statut' => 'success',
                    'date_paiement' => now(),
                ]);
                
                $this->sendReceiptEmail($transaction);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement effectué avec succès!',
                    'receipt_id' => $transaction->id,
                ]);
            } else {
                $transaction->update(['statut' => 'failed']);

                return response()->json(['success' => false, 'message' => 'Le paiement a échoué.'], 400);
            }

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du traitement du paiement: '.$e->getMessage()], 500);
        }
    }

    private function sendReceiptEmail($paiement) 
    {
        $paiement->load(['eleve', 'eleve.classe', 'eleve.tuteurs']);
        
        // Le PDF n'est plus généré par le backend. L'email contient juste les informations HTML.
        $pdfContent = null;

        // Send email to parent if email exists
        $parent = $paiement->eleve->tuteurs->first();
        $email = $parent ? $parent->email : null;
        if (!$email) {
            $email = optional($paiement->eleve)->email;
        }
        
        if (!empty($email)) {
            try {
                Mail::to($email)->send(new PaymentReceiptMail($paiement, $pdfContent));
            } catch (\Exception $e) {
                Log::error('Erreur lors de l\'envoi du reçu par email: ' . $e->getMessage());
            }
        }

        // --- ENVOI DE LA NOTIFICATION PUSH FIREBASE AUX PARENTS ---
        try {
            $tuteurs = $paiement->eleve->tuteurs;
            if ($tuteurs && $tuteurs->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\PaiementReussiNotification($paiement));
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de la Push Notification Paiement: ' . $e->getMessage());
        }
    }

    public function generateReceipt($id)
    {
        $paiement = Paiement::with(['eleve', 'eleve.classe', 'eleve.tuteurs'])->findOrFail($id);

        if ($paiement->statut !== 'success') {
            return response()->json(['success' => false, 'message' => 'Le reçu n\'est disponible que pour les paiements réussis.'], 400);
        }

        // Renvoie uniquement les données JSON pour que le frontend génère le PDF et le QR code localement
        return response()->json([
            'success' => true,
            'paiement' => $paiement
        ]);
    }

    // Méthode pour vérifier manuellement le statut d'un paiement
    public function checkPaymentStatus($id)
    {
        $paiement = Paiement::findOrFail($id);

        if ($paiement->methode === 'fedapay' && $paiement->reference_externe) {
            try {
                \FedaPay\FedaPay::setApiKey(env('FEDAPAY_SECRET_KEY'));
                $fedapayTransaction = \FedaPay\Transaction::retrieve($paiement->reference_externe);

                if ($fedapayTransaction->status === 'approved' && $paiement->statut !== 'success') {
                    $paiement->update([
                        'statut' => 'success',
                        'date_paiement' => now(),
                    ]);
                    
                    $this->sendReceiptEmail($paiement);

                    return response()->json(['success' => true, 'message' => 'Paiement vérifié et confirmé avec succès!']);
                }
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Erreur lors de la vérification: '.$e->getMessage()], 500);
            }
        }

        return response()->json(['success' => true, 'message' => 'Statut du paiement: '.$paiement->statut]);
    }
}
