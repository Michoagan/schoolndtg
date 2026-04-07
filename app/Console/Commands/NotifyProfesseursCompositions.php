<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\HoraireComposition;
use App\Models\Professeur;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyProfesseursCompositions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:compositions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notifier les professeurs de leurs dates de composition prévues dans 2 semaines (14 jours)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetDate = Carbon::today()->addDays(14)->toDateString();
        $this->info("Recherche des compositions prévues le {$targetDate}...");

        $horaires = HoraireComposition::with(['session.classe', 'matiere'])
            ->whereDate('date_composition', $targetDate)
            ->get();

        if ($horaires->isEmpty()) {
            $this->info("Aucune composition trouvée pour le {$targetDate}.");
            return;
        }

        $count = 0;

        foreach ($horaires as $horaire) {
            $session = $horaire->session;
            $matiere = $horaire->matiere;

            if (!$session || !$session->classe || !$matiere) {
                continue;
            }

            $classe = $session->classe;

            // Trouver le professeur de la matière dans cette classe
            $professeur = Professeur::where('matiere_id', $matiere->id)
                ->whereHas('classes', function ($query) use ($classe) {
                    $query->where('classes.id', $classe->id);
                })
                ->first();

            if ($professeur && !empty($professeur->phone)) {
                $dateFormatted = Carbon::parse($horaire->date_composition)->format('d/m/Y');
                $heureDebut = Carbon::parse($horaire->heure_debut)->format('H:i');
                $heureFin = Carbon::parse($horaire->heure_fin)->format('H:i');

                $texteWhatsapp = "📅 *Rappel de Composition* \n\n";
                $texteWhatsapp .= "Bonjour *{$professeur->first_name} {$professeur->last_name}*,\n\n";
                $texteWhatsapp .= "Ceci est un rappel automatique de la Direction.\n";
                $texteWhatsapp .= "Vous êtes programmé(e) pour une de vos compositions :\n\n";
                $texteWhatsapp .= "🏫 Classe : *{$classe->nom}*\n";
                $texteWhatsapp .= "📚 Matière : *{$matiere->nom}*\n";
                $texteWhatsapp .= "🗓 Date : *{$dateFormatted}*\n";
                $texteWhatsapp .= "⏰ Heure : de *{$heureDebut}* à *{$heureFin}*\n\n";
                $texteWhatsapp .= "Merci de prendre les dispositions nécessaires pour la préparation des sujets. Bonne journée !";

                try {
                    $response = Http::post(env('WHATSAPP_BOT_URL', 'http://localhost:3000') . '/api/messages/send', [
                        'phone' => $professeur->phone,
                        'message' => $texteWhatsapp
                    ]);

                    if ($response->successful()) {
                        $this->info("Notification envoyée à {$professeur->first_name} {$professeur->last_name} ({$professeur->phone}) pour {$matiere->nom}.");
                        $count++;
                    } else {
                        Log::error("Échec envoi WhatsApp pour professeur {$professeur->id}: " . $response->body());
                    }
                } catch (\Exception $reqEx) {
                    Log::error('Erreur HTTP vers Bot WhatsApp lors du Job de rappel composition : ' . $reqEx->getMessage());
                }
            } else {
                Log::warning("Aucun professeur avec un numéro de téléphone valide n'a été trouvé pour la composition ID " . $horaire->id . " (Matiere: {$matiere->nom}, Classe: {$classe->nom})");
            }
        }

        $this->info("Terminé. {$count} notification(s) envoyée(s).");
    }
}
