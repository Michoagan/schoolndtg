<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NoteAddedNotification extends Notification
{
    use Queueable;

    protected $note;

    /**
     * Create a new notification instance.
     */
    public function __construct($note)
    {
        $this->note = $note;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', \App\Channels\FcmChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    public function toArray(object $notifiable): array
    {
        $matiereNom = $this->note->matiere ? $this->note->matiere->nom : 'une matière';
        $message = "Une nouvelle note a été enregistrée en {$matiereNom} pour le trimestre {$this->note->trimestre}.";
        
        return [
            'titre' => 'Nouvelle Note Ajoutée',
            'message' => $message,
            'eleve_id' => $this->note->eleve_id,
            'note_id' => $this->note->id,
            'type_notification' => 'note',
        ];
    }

    public function toFcm($notifiable)
    {
        if (empty($notifiable->fcm_token)) {
            return;
        }

        try {
            $factory = (new \Kreait\Firebase\Factory)->withServiceAccount(config('services.firebase.credentials'));
            $messaging = $factory->createMessaging();

            $matiereNom = $this->note->matiere ? $this->note->matiere->nom : 'une matière';
            $messageText = "Une nouvelle note a été enregistrée en {$matiereNom} pour le trimestre {$this->note->trimestre}.";

            $messageFCM = \Kreait\Firebase\Messaging\CloudMessage::new()
                ->withToken($notifiable->fcm_token)
                ->withNotification(\Kreait\Firebase\Messaging\Notification::create('Nouvelle Note Ajoutée', $messageText))
                ->withData([
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'type' => 'note',
                    'eleve_id' => (string)$this->note->eleve_id,
                    'note_id' => (string)$this->note->id,
                ]);

            $messaging->send($messageFCM);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur d\'envoi FCM (NoteAdded) : ' . $e->getMessage());
        }
    }
}
