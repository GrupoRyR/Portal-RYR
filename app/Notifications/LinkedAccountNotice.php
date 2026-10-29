<?php

namespace App\Notifications;

use App\Branding\MailBrand;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail to every account of a person when two of them were linked
 * (App\Auth\LinkedAccounts), so that a link nobody asked for is noticed.
 * Sent synchronously (no queue worker on shared hosting).
 */
class LinkedAccountNotice extends Notification
{
    public function __construct(
        public readonly string $firstCompany,
        public readonly ?string $firstEmail,
        public readonly string $secondCompany,
        public readonly ?string $secondEmail,
        public readonly CarbonImmutable $linkedAt,
    ) {}

    /**
     * The signed-in account first, then the account it linked.
     */
    public static function for(User $current, User $other): self
    {
        return new self(
            Company::displayName($current->company),
            $current->email,
            Company::displayName($other->company),
            $other->email,
            CarbonImmutable::now(),
        );
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Painted with the recipient's company (one company per screen).
            ->markdown('notifications::email', [
                'brand' => MailBrand::for($notifiable instanceof User ? $notifiable->company : null),
            ])
            ->subject('Se vincularon dos cuentas tuyas del portal')
            ->greeting('Hola:')
            ->line('Se vincularon estas dos cuentas tuyas del portal:')
            ->line("{$this->firstCompany}: {$this->firstEmail}")
            ->line("{$this->secondCompany}: {$this->secondEmail}")
            ->line('Cada cuenta conserva sus propios permisos y aplicaciones.')
            ->line('Fecha: '.$this->linkedAt->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'))
            ->line('Si no fuiste tú, avisa a TI.')
            ->salutation('Portal RYR');
    }
}
