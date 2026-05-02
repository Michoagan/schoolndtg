<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use App\Notifications\AccountApproved;
use App\Notifications\AccountRejected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminDirectionController extends Controller
{
    // Constructor removed - Middleware handled in routes or globally
    // public function __construct() { ... }

    public function dashboard()
    {
        $stats = [
            'total' => Direction::count(),
            'pending' => Direction::where('approved_by_admin', false)->count(),
            'active' => Direction::where('approved_by_admin', true)->where('is_active', true)->count(),
            'inactive' => Direction::where('is_active', false)->count(),
        ];

        $recentRegistrations = Direction::where('approved_by_admin', false)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'recentRegistrations' => $recentRegistrations,
        ]);
    }

    public function pendingAccounts()
    {
        $pendingAccounts = Direction::where('approved_by_admin', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'pendingAccounts' => $pendingAccounts,
        ]);
    }

    public function showAccount($id)
    {
        $account = Direction::findOrFail($id);

        return response()->json([
            'success' => true,
            'account' => $account,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:M,F'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:direction,email'],
            'phone' => ['required', 'string', 'max:20'],
            'role' => ['required', 'in:directeur,censeur,surveillant,secretariat,comptable,caisse'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = Direction::create([
            'last_name' => $request->last_name,
            'first_name' => $request->first_name,
            'gender' => $request->gender,
            'birth_date' => $request->birth_date ?? null, // Optional for admin creation
            'role' => $request->role,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => \Illuminate\Support\Facades\Hash::make($request->password),
            'is_active' => true, // Auto-active
            'approved_by_admin' => true, // Auto-approved
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès.',
            'user' => $user,
        ], 201);
    }

    public function approveAccount(Request $request, $id)
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $account = Direction::findOrFail($id);

        \Log::info('Avant approbation', ['account' => $account->toArray()]);

        // Vérifier si le compte n'est pas déjà approuvé
        if ($account->approved_by_admin) {
            return response()->json(['success' => false, 'message' => 'Ce compte est déjà approuvé.'], 400);
        }

        $updateData = [
            'is_active' => true,
            'approved_by_admin' => true,
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ];

        // Ajouter les notes seulement si elles sont fournies
        if ($request->filled('notes')) {
            $updateData['admin_notes'] = $request->notes;
        }

        \Log::info('Données de mise à jour', $updateData);

        // Mettre à jour le compte
        $updated = $account->update($updateData);

        \Log::info('Résultat de la mise à jour', ['updated' => $updated]);

        // Recharger le compte depuis la base
        $account->refresh();
        \Log::info('Après approbation', ['account' => $account->toArray()]);

        // Envoi de notification d'approbation
        try {
            $account->notify(new AccountApproved);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi notification approbation: '.$e->getMessage());

            return response()->json([
                'success' => true,
                'message' => 'Compte approuvé mais échec de l\'envoi de l\'email.',
                'warning' => true,
            ]);
        }

        // Récupérer le nouveau nombre de comptes en attente
        $pendingCount = Direction::where('approved_by_admin', false)->count();

        return response()->json([
            'success' => true,
            'message' => 'Le compte de '.$account->first_name.' '.$account->last_name.' a été approuvé avec succès.',
            'pending_count' => $pendingCount,
        ]);
    }

    public function rejectAccount(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $account = Direction::findOrFail($id);

        \Log::info('Avant suppression', ['account' => $account->toArray()]);

        // Envoi de notification de rejet AVANT suppression
        try {
            $account->notify(new AccountRejected($request->rejection_reason));
        } catch (\Exception $e) {
            \Log::error('Erreur envoi notification rejet: '.$e->getMessage());
        } catch (\Exception $e) {
            \Log::error('Erreur envoi notification rejet: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi de la notification.'], 500);
        }

        // Sauvegarder les données avant suppression pour les logs
        $accountData = $account->toArray();

        // Supprimer le compte
        $deleted = $account->delete();

        \Log::info('Résultat de la suppression', ['deleted' => $deleted]);

        // Récupérer le nouveau nombre de comptes en attente
        $pendingCount = Direction::where('approved_by_admin', false)->count();

        // Loguer la suppression
        \Log::info('Compte rejeté par l\'admin', [
            'admin_id' => Auth::id(),
            'rejected_account' => $accountData,
            'reason' => $request->rejection_reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Le compte a été rejeté avec succès.',
            'pending_count' => $pendingCount,
        ]);
    }

    public function toggleAccountStatus($id)
    {
        $account = Direction::where('approved_by_admin', true)->findOrFail($id);

        $newStatus = ! $account->is_active;
        $account->update([
            'is_active' => $newStatus,
        ]);

        $status = $newStatus ? 'activé' : 'désactivé';
        $status = $newStatus ? 'activé' : 'désactivé';

        return response()->json([
            'success' => true,
            'message' => "Le compte a été $status avec succès.",
            'is_active' => $newStatus,
        ]);
    }

    public function updateAccount(Request $request, $id)
    {
        $account = Direction::findOrFail($id);

        $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:direction,email,'.$id],
            'phone' => ['required', 'string', 'max:20'],
            'role' => ['required', 'in:directeur,censeur,surveillant,secretariat,comptable,caisse'],
        ]);

        $account->update($request->only(['last_name', 'first_name', 'email', 'phone', 'role']));

        $account->update($request->only(['last_name', 'first_name', 'email', 'phone', 'role']));

        return response()->json([
            'success' => true,
            'message' => 'Les informations du compte ont été mises à jour avec succès.',
            'account' => $account,
        ]);
    }

    public function allAccounts(Request $request)
    {
        $query = Direction::orderBy('created_at', 'desc');

        // Filtrage par statut
        if ($request->has('status')) {
            switch ($request->status) {
                case 'active':
                    $query->where('approved_by_admin', true)->where('is_active', true);
                    break;
                case 'inactive':
                    $query->where('approved_by_admin', true)->where('is_active', false);
                    break;
                case 'pending':
                    $query->where('approved_by_admin', false);
                    break;
            }
        }

        $accounts = $query->get();

        return response()->json([
            'success' => true,
            'accounts' => $accounts,
        ]);
    }
}
