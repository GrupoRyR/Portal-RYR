<?php

namespace App\Notifications;

use App\Branding\MailBrand;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Recordatorio: confirma la lectura de …» to a person who has not
 * confirmed a mandatory announcement (App\Announcements\AnnouncementReminders).
 * The title and a link to the announcement only: the text is read in the
 * portal, where the person confirms it. Sent synchronously by the cron.
 */
class AnnouncementReminderNotice extends Notification
{
    public function __construct(public readonly Announcement $announcement) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->announcement->title;

        return (new MailMessage)
            // Painted with the recipient's company (one company per screen).
            ->markdown('notifications::email', [
                'brand' => MailBrand::for($notifiable instanceof User ? $notifiable->company : null),
            ])
            ->subject("Recordatorio: confirma la lectura de «{$title}»")
            ->greeting('Hola:')
            ->line("Tienes pendiente el anuncio «{$title}» en el portal.")
            ->line('Este anuncio pide confirmar que lo leíste. Ábrelo y pulsa «Confirmar lectura».')
            ->action('Ver el anuncio', route('announcements.show', $this->announcement))
            ->line('Si ya lo confirmaste, no tienes que hacer nada más.')
            ->salutation('Portal RYR');
    }
}
