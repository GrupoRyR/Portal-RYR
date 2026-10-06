<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail to the portal administrators when a Microsoft organization gains or
 * changes access: a company was created, its tenant changed, or it was
 * activated. Sent synchronously (no queue worker on shared hosting).
 */
class CompanyChangedNotice extends Notification
{
    public const CREATED = 'created';

    public const TENANT_CHANGED = 'tenant_changed';

    public const ACTIVATED = 'activated';

    public function __construct(
        public readonly Company $company,
        public readonly string $event,
        public readonly ?string $actorName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->company->name;

        $mail = (new MailMessage)
            ->subject(match ($this->event) {
                self::CREATED => "Nueva empresa en el portal: {$name}",
                self::TENANT_CHANGED => "Cambió el inquilino de {$name}",
                default => "{$name} ya puede entrar al portal",
            })
            ->greeting('Hola:')
            ->line(match ($this->event) {
                self::CREATED => "Se registró la empresa {$name}. Sigue inactiva hasta que su administrador de Microsoft dé el consentimiento y se active.",
                self::TENANT_CHANGED => "La empresa {$name} ahora entra con otra organización de Microsoft. Los usuarios del inquilino anterior ya no tienen acceso.",
                default => "La empresa {$name} quedó activa: sus usuarios ya pueden iniciar sesión.",
            })
            ->line('ID de inquilino: '.$this->company->tenant_id)
            ->line('Hecho por: '.($this->actorName ?? 'el sistema'))
            ->line('Fecha: '.now()->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY, HH:mm'))
            ->action('Ver la empresa', route('admin.companies.show', $this->company))
            ->line('Si no reconoces este cambio, revisa la auditoría y avisa al área de TI.')
            ->salutation('Portal RYR');

        return $mail;
    }
}
