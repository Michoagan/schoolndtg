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
    protected $role;

    /**
     * Create a new notification instance.
     */
    public function __construct($note, string $role = 'parent')
    {
        $this->note = $note;
        $this->role = $role;
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
        $nouvelleNote = $this->note->devoir_2 ?? $this->note->devoir_1 ?? $this->note->interro_4 ?? $this->note->interro_3 ?? $this->note->interro_2 ?? $this->note->interro_1 ?? $this->note->premier_devoir ?? $this->note->premier_interro ?? 0;
        
        $aiService = app(\App\Services\AiService::class);
        $message = $aiService->generatePushAlertForNewGrade($matiereNom, (float)$nouvelleNote, null, $this->role);
        
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
            $nouvelleNote = $this->note->devoir_2 ?? $this->note->devoir_1 ?? $this->note->interro_4 ?? $this->note->interro_3 ?? $this->note->interro_2 ?? $this->note->interro_1 ?? $this->note->premier_devoir ?? $this->note->premier_interro ?? 0;

            $aiService = app(\App\Services\AiService::class);
            $messageText = $aiService->generatePushAlertForNewGrade($matiereNom, (float)$nouvelleNote, null, $this->role);

            $messageFCM = \Kreait\Firebase\Messaging\CloudMessage::fromArray([
                'token' => $notifiable->fcm_token,
                'notification' => [
                    'title' => 'Nouvelle Note Ajoutée',
                    'body' => $messageText,
                ],
                'data' => [
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'type' => 'note',
                    'eleve_id' => (string)$this->note->eleve_id,
                    'note_id' => (string)$this->note->id,
                ],
            ]);

            $messaging->send($messageFCM);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur d\'envoi FCM (NoteAdded) : ' . $e->getMessage());
        }
    }
}
