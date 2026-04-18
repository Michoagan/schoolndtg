<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class AlerteChuteNotesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $eleve;
    protected $matiere;
    protected $raison;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($eleve, $matiere, $raison)
    {
        $this->eleve = $eleve;
        $this->matiere = $matiere;
        $this->raison = $raison; // Ex: "a obtenu deux notes consécutives en dessous de la moyenne" ou "baisse de 3 points"
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database', \App\Channels\FcmChannel::class];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'type' => 'alerte_notes',
            'titre' => 'Alerte Baisse de Résultats',
            'message' => "Attention: {$this->eleve->prenom} {$this->raison} en {$this->matiere->nom}.",
            'eleve_id' => $this->eleve->id,
            'matiere_id' => $this->matiere->id,
            'date' => now()->toDateTimeString(),
        ];
    }

    /**
     * Envoi de la notification Push via Firebase.
     *
     * @param mixed $notifiable
     */
    public function toFcm($notifiable)
    {
        if (empty($notifiable->fcm_token)) {
            \Log::warning("AlerteChuteNotesNotification: Utilisateur ID {$notifiable->id} n'a pas de device token.");
            return;
        }

        try {
            $factory = (new Factory)
                ->withServiceAccount(config('services.firebase.credentials'));
            
            $messaging = $factory->createMessaging();

            $title = "Alerte Scolaire 📉";
            $body = "Attention : {$this->eleve->prenom} {$this->raison} en {$this->matiere->nom}. Un suivi est recommandé.";

            $message = \Kreait\Firebase\Messaging\CloudMessage::fromArray([
                'token' => $notifiable->fcm_token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => [
                    'type' => 'alerte_notes',
                    'eleve_id' => (string) $this->eleve->id,
                    'matiere_id' => (string) $this->matiere->id,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ]
            ]);

            $messaging->send($message);
            \Log::info("FCM AlerteChuteNotes envoyé à l'utilisateur ID {$notifiable->id} avec succès.");

        } catch (\Exception $e) {
            \Log::error("Erreur FCM AlerteChuteNotes : " . $e->getMessage());
        }
    }
}
