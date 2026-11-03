<?php

namespace App\Notifications;

use App\Branding\MailBrand;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail to every account of a person when two of them were linked, or when
 * one was unlinked by the person or an administrator
 * (App\Auth\LinkedAccounts), so that a change nobody asked for is
 * noticed. Sent synchronously (no queue worker on shared hosting).
 */
class LinkedAccountNotice extends Notification
{
    public const LINKED = 'linked';

    public const UNLINKED = 'unlinked';

    /**
     * @param  string  $kind  {@see LINKED} (both accounts) or {@see UNLINKED} (the first one only).
     * @param  string|null  $reason  Who unlinked it: `user` or `admin`.
     */
    public function __construct(
        public readonly string $firstCompany,
        public readonly ?string $firstEmail,
        public readonly ?string $secondCompany,
        public readonly ?string $secondEmail,
        public readonly CarbonImmutable $happenedAt,
        public readonly string $kind = self::LINKED,
        public readonly ?string $reason = null,
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
     * @param  string  $reason  `user` or `admin` (App\Auth\LinkedAccounts).
     */
    public static function unlinked(User $account, string $reason): self
    {
        return new self(
            Company::displayName($account->company),
            $account->email,
            null,
            null,
            CarbonImmutable::now(),
            self::UNLINKED,
            $reason,
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
        $byAdmin = $this->kind === self::UNLINKED && $this->reason === 'admin';

        $mail = (new MailMessage)
            // Painted with the recipient's company (one company per screen).
            ->markdown('notifications::email', [
                'brand' => MailBrand::for($notifiable instanceof User ? $notifiable->company : null),
            ])
            ->greeting('Hola:');

        if ($this->kind === self::UNLINKED) {
            $mail->subject('Se desvinculó una cuenta tuya del portal')
                ->line('Esta cuenta tuya del portal ya no está vinculada con tus otras cuentas:')
                ->line("{$this->firstCompany}: {$this->firstEmail}")
                ->line($byAdmin ? 'Lo hizo un administrador del portal.' : 'Lo hiciste tú desde el portal.')
                ->line('Cada cuenta conserva sus propios permisos y aplicaciones.');
        } else {
            $mail->subject('Se vincularon dos cuentas tuyas del portal')
                ->line('Se vincularon estas dos cuentas tuyas del portal:')
                ->line("{$this->firstCompany}: {$this->firstEmail}")
                ->line("{$this->secondCompany}: {$this->secondEmail}")
                ->line('Cada cuenta conserva sus propios permisos y aplicaciones.');
        }

        return $mail
            ->line('Fecha: '.$this->happenedAt->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'))
            ->line($byAdmin ? 'Si no lo pediste, avisa a TI.' : 'Si no fuiste tú, avisa a TI.')
            ->salutation('Portal RYR');
    }
}
