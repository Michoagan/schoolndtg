<?php

namespace App\Services;

use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Professeur;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service centralisé pour l'envoi de messages WhatsApp via le bot NDTG.
 * Non-bloquant : une erreur WhatsApp ne fait jamais échouer la requête principale.
 */
class WhatsAppService
{
    private static string $botUrl = '';

    private static function botUrl(): string
    {
        if (empty(self::$botUrl)) {
            self::$botUrl = rtrim(
                env('WHATSAPP_BOT_URL', 'https://whatsappndtg-production-b710.up.railway.app'),
                '/'
            );
        }
        return self::$botUrl;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  API PUBLIQUE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Envoie un message WhatsApp à un numéro.
     */
    public static function send(string $phone, string $message): void
    {
        if (empty($phone) || empty($message)) return;

        try {
            Http::timeout(12)->post(self::botUrl() . '/send', [
                'phone'   => $phone,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] Échec envoi vers ' . $phone . ' : ' . $e->getMessage());
        }
    }

    /**
     * Envoie à tous les tuteurs (parents) d'un élève.
     * Le modèle Tuteur utilise le champ "telephone".
     */
    public static function sendToParentsOf(int $eleveId, string $message): void
    {
        try {
            $eleve = Eleve::with('tuteurs')->find($eleveId);
            if (!$eleve) return;

            foreach ($eleve->tuteurs as $tuteur) {
                $phone = $tuteur->telephone ?? '';
                if (!empty($phone)) {
                    self::send($phone, $message);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] sendToParentsOf(' . $eleveId . ') : ' . $e->getMessage());
        }
    }

    /**
     * Envoie un message WhatsApp à un professeur.
     * Le modèle Professeur utilise le champ "phone".
     */
    public static function sendToProfesseur(Professeur $prof, string $message): void
    {
        $phone = $prof->phone ?? '';
        if (!empty($phone)) {
            self::send($phone, $message);
        }
    }

    /**
     * Envoie à tous les parents des élèves d'une classe.
     */
    public static function sendToParentsOfClasse(int $classeId, string $message): void
    {
        try {
            $eleves = Eleve::where('classe_id', $classeId)
                ->with('tuteurs')
                ->get();

            foreach ($eleves as $eleve) {
                foreach ($eleve->tuteurs as $tuteur) {
                    $phone = $tuteur->telephone ?? '';
                    if (!empty($phone)) {
                        self::send($phone, $message);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] sendToParentsOfClasse(' . $classeId . ') : ' . $e->getMessage());
        }
    }

    /**
     * Envoie à tous les professeurs actifs.
     */
    public static function sendToAllProfesseurs(string $message): void
    {
        try {
            $profs = Professeur::where('is_active', true)->get();
            foreach ($profs as $prof) {
                self::sendToProfesseur($prof, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] sendToAllProfesseurs : ' . $e->getMessage());
        }
    }

    /**
     * Envoie à tous les parents de toutes les classes.
     */
    public static function sendToAllParents(string $message): void
    {
        try {
            $classes = Classe::pluck('id');
            foreach ($classes as $classeId) {
                self::sendToParentsOfClasse($classeId, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] sendToAllParents : ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  MESSAGES PRÉDÉFINIS (templates)
    // ─────────────────────────────────────────────────────────────────────

    public static function msgNouvelleNote(
        string $eleveNom,
        string $matiere,
        string $typeEval,
        float  $note,
        bool   $estChute = false
    ): string {
        $icon = $note >= 10 ? '✅' : '⚠️';
        $msg  = "📚 *Notre Dame Pro — Nouvelle Note*\n\n";
        $msg .= "Élève : *{$eleveNom}*\n";
        $msg .= "Matière : *{$matiere}*\n";
        $msg .= "Évaluation : *{$typeEval}*\n";
        $msg .= "{$icon} Note : *{$note}/20*\n";
        if ($estChute) {
            $msg .= "\n⚠️ *ALERTE* : Une chute de notes a été détectée. Merci de suivre cela de près.\n";
        }
        $msg .= "\nConnectez-vous à l'espace parent pour plus de détails.";
        return $msg;
    }

    public static function msgAbsence(
        string $eleveNom,
        string $date,
        string $cours
    ): string {
        $msg  = "🔔 *Notre Dame Pro — Absence Signalée*\n\n";
        $msg .= "Élève : *{$eleveNom}*\n";
        $msg .= "Date : *{$date}*\n";
        $msg .= "Cours : *{$cours}*\n\n";
        $msg .= "Merci de vous assurer que votre enfant est bien présent en classe.\n";
        $msg .= "Connectez-vous à l'espace parent pour plus de détails.";
        return $msg;
    }

    public static function msgExerciceNonFait(
        string $eleveNom,
        string $matiere,
        string $dateExercice
    ): string {
        $msg  = "📝 *Notre Dame Pro — Exercice Non Fait*\n\n";
        $msg .= "Élève : *{$eleveNom}*\n";
        $msg .= "Matière : *{$matiere}*\n";
        $msg .= "Date : *{$dateExercice}*\n\n";
        $msg .= "Votre enfant n'a pas fait l'exercice demandé. Merci d'y veiller.\n";
        $msg .= "Connectez-vous à l'espace parent pour plus de détails.";
        return $msg;
    }

    public static function msgComposition(
        string $classe,
        string $matiere,
        string $dateCompo,
        string $typeCompo
    ): string {
        $msg  = "📅 *Notre Dame Pro — Programmation Composition*\n\n";
        $msg .= "Classe : *{$classe}*\n";
        $msg .= "Matière : *{$matiere}*\n";
        $msg .= "Type : *{$typeCompo}*\n";
        $msg .= "📆 Date : *{$dateCompo}*\n\n";
        $msg .= "Préparez bien votre enfant pour cette évaluation.";
        return $msg;
    }

    public static function msgNonPaiement(
        string $eleveNom,
        string $classe,
        string $tranche,
        string $montant
    ): string {
        $msg  = "💰 *Notre Dame Pro — Rappel Scolarité*\n\n";
        $msg .= "Élève : *{$eleveNom}*\n";
        $msg .= "Classe : *{$classe}*\n";
        $msg .= "Tranche : *{$tranche}*\n";
        $msg .= "Montant dû : *{$montant} FCFA*\n\n";
        $msg .= "Merci de régulariser votre situation au plus tôt.";
        return $msg;
    }

    public static function msgCommunique(string $titre, string $contenu): string
    {
        $msg  = "📢 *Notre Dame Pro — Communiqué*\n\n";
        $msg .= "*{$titre}*\n\n";
        $msg .= $contenu . "\n\n";
        $msg .= "_Direction — Notre Dame Pro_";
        return $msg;
    }
}
