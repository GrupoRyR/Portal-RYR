<?php

namespace App\Assistant\Failover;

use App\Branding\MailBrand;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail to the super admins when the assistant's primary provider fails
 * for billing or credentials and the assistant moves to the secondary
 * (App\Assistant\Failover\ProviderFailover), once per cooldown window.
 */
class PrimaryDownNotice extends Notification
{
    public function __construct(
        public readonly string $provider,
        public readonly string $reason,
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
        return (new MailMessage)
            ->markdown('notifications::email', [
                'brand' => MailBrand::for($notifiable instanceof User ? $notifiable->company : null),
            ])
            ->subject('Anthropic sin saldo o llave inválida; el asistente usa el proveedor secundario.')
            ->greeting('Hola:')
            ->line('Anthropic sin saldo o llave inválida; el asistente usa el proveedor secundario.')
            ->line('Proveedor: '.$this->provider.' · motivo: '.$this->reason.'.')
            ->line('Revise el saldo, el límite de gasto y la llave en la consola de Anthropic. El portal vuelve a probar el proveedor principal en cada conversación nueva cuando pase la espera.')
            ->line('Mientras tanto, las respuestas salen del proveedor secundario: revise que su uso esté aprobado para los datos del portal (Ley 1581).')
            ->action('Ver la configuración', route('admin.settings.index'))
            ->salutation('Portal RYR');
    }
}
