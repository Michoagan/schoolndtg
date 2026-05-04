<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use App\Models\PasswordResetCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Mail;
use App\Notifications\PasswordResetCodeNotification;

class DirectionController extends Controller
{
    // public function inscrit() removed

    public function directeurDashboard()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'eleves_count' => \App\Models\Eleve::count(),
                    'professeurs_count' => \App\Models\Professeur::count(),
                    'classes_count' => \App\Models\Classe::count(),
                    // 'recettes_mois' => \App\Models\Paiement::whereMonth('created_at', now()->month)->sum('montant'),
                    // 'recettes_total' => \App\Models\Paiement::sum('montant'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur chargement dashboard'], 500);
        }
    }


    public function register(Request $request)
{
    $request->validate([
        'last_name' => ['required', 'string', 'max:255'],
        'first_name' => ['required', 'string', 'max:255'],
        'gender' => ['required', 'in:M,F'],
        'birth_date' => ['required', 'date'],
        'role' => ['required', 'in:directeur,censeur,surveillant,secretariat,comptable,caisse'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:direction_users,email'], // ✅ table corrigée
        'phone' => ['required', 'string', 'max:20'],
        'password' => ['required', 'confirmed', Rules\Password::defaults()],
        'redirect_to' => ['sometimes', 'string']
    ]);

    $user = Direction::create([
        'last_name' => $request->last_name,
        'first_name' => $request->first_name,
        'gender' => $request->gender,
        'birth_date' => $request->birth_date,
        'role' => $request->role,
        'email' => $request->email,
        'phone' => $request->phone,
        'password' => Hash::make($request->password),
        'is_active' => false,
        'approved_by_admin' => false,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Votre inscription a été soumise avec succès. Elle doit être approuvée par un administrateur.',
        'data' => $user
    ], 201);
}

    // pending() removed
    // redirectBasedOnRole() removed
    // showLoginForm() removed

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Vérifier les identifiants manuellement pour Sanctum
        $user = Direction::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Les identifiants ne correspondent pas à nos enregistrements.',
            ], 401);
        }

        // Vérifier si le compte est actif et approuvé
        if (!$user->is_active || !$user->approved_by_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Votre compte n\'est pas encore activé. Veuillez attendre l\'approbation de l\'administrateur.',
            ], 403);
        }

        // Créer un token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    //deconnexion
    public function logout(Request $request)
    {
        // Révoquer le token actuel
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);
    }

    /**
     * Afficher le formulaire de demande de code
     */
    // showForgotPasswordForm() removed


    /**
     * Générer et envoyer le code secret
     */
    public function sendResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:direction,email'
        ], [
            'email.exists' => 'Aucun compte trouvé avec cette adresse email.'
        ]);

        // Vérifier d'abord si l'utilisateur existe
        $user = Direction::where('email', $request->email)->first();

        if (!$user) {
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
            'expires_at' => now()->addMinutes(15)
        ]);

        try {
            // Envoyer la notification avec le code
            // Envoyer le code de réinitialisation par WhatsApp
            if (!empty($user->phone)) {
                $texteWhatsapp = "🔐 *Réinitialisation de Mot de passe*\n\n";
                $texteWhatsapp .= "Votre code secret est : *$code*\n";
                $texteWhatsapp .= "Ce code est valide pour 15 minutes. Ne le partagez avec personne.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(15)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $user->phone,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Reset Code Direction) : ' . $reqEx->getMessage());
                }
            } else {
                \Illuminate\Support\Facades\Log::warning("DIRECTION RESET CODE pour {$user->email}: $code (Numéro manquant)");
            }

            return response()->json([
                'success' => true,
                'message' => 'Code de réinitialisation envoyé avec succès.',
                'email' => $request->email
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: ' . $e->getMessage());
             return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi du code. Veuillez réessayer.'
            ], 500);
        }
    }

    /**
     * Afficher le formulaire de vérification du code
     */
    // showVerifyCodeForm() removed


    /**
     * Vérifier le code secret
     */
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6'
        ]);

        $resetCode = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        if (!$resetCode) {
            return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.'], 400);
        }

        // Code valide
        return response()->json([
            'success' => true, 
            'message' => 'Code valide.',
            'code' => $request->code,
            'email' => $request->email
        ]);
    }

    /**
     * Afficher le formulaire de réinitialisation
     */
    // showResetForm() removed


    /**
     * Réinitialiser le mot de passe
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Vérifier à nouveau le code
        $resetCode = PasswordResetCode::where('email', $request->email)
            ->where('code', $request->code)
            ->where('expires_at', '>', now())
            ->first();

        if (!$resetCode) {
             return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.'], 400);
        }

        // Trouver l'utilisateur et mettre à jour le mot de passe
        $user = Direction::where('email', $request->email)->first();

        if ($user) {
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            // Supprimer le code utilisé
            PasswordResetCode::where('email', $request->email)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe réinitialisé avec succès.'
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Utilisateur non trouvé.'], 404);
    }

    /**
     * Renvoyer un nouveau code
     */
    public function resendCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:direction,email'
        ]);

        // Vérifier si l'utilisateur existe
        $user = Direction::where('email', $request->email)->first();
        
        if (!$user) {
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
            'expires_at' => now()->addMinutes(15)
        ]);

        try {
            // Envoyer le code de réinitialisation par WhatsApp
            if (!empty($user->phone)) {
                $texteWhatsapp = "🔐 *Réinitialisation de Mot de passe*\n\n";
                $texteWhatsapp .= "Votre code secret est : *$code*\n";
                $texteWhatsapp .= "Ce code est valide pour 15 minutes. Ne le partagez avec personne.";

                try {
                    \Illuminate\Support\Facades\Http::timeout(15)->post(env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production.up.railway.app') . '/send', [
                        'phone' => $user->phone,
                        'message' => $texteWhatsapp
                    ]);
                } catch (\Exception $reqEx) {
                    \Illuminate\Support\Facades\Log::error('Erreur HTTP WhatsApp (Reset Code Direction) : ' . $reqEx->getMessage());
                }
            } else {
                \Illuminate\Support\Facades\Log::warning("DIRECTION RESET CODE pour {$user->email}: $code (Numéro manquant)");
            }

            return response()->json([
                'success' => true,
                'message' => 'Nouveau code envoyé avec succès.'
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'envoi du code.'], 500);
        }
    }
}
