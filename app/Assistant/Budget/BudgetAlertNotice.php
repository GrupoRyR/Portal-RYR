<?php

namespace App\Assistant\Budget;

use App\Branding\MailBrand;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail to the super admins when the assistant reaches 80 % or 100 % of its
 * monthly budget (App\Assistant\Budget\AssistantBudget). Sent
 * synchronously, like the portal's other admin notices.
 */
class BudgetAlertNotice extends Notification
{
    public function __construct(
        public readonly int $threshold,
        public readonly float $spent,
        public readonly float $budget,
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
        $money = fn (float $value) => 'US$ '.number_format($value, 2, ',', '.');
        $reached = $this->threshold >= 100;

        return (new MailMessage)
            ->markdown('notifications::email', [
                'brand' => MailBrand::for($notifiable instanceof User ? $notifiable->company : null),
            ])
            ->subject($reached
                ? 'El asistente del portal alcanzó su límite de uso del mes'
                : "El asistente del portal ya usó el {$this->threshold} % de su presupuesto del mes")
            ->greeting('Hola:')
            ->line('Este mes el asistente lleva '.$money($this->spent).' de un presupuesto de '.$money($this->budget).'.')
            ->line($reached
                ? 'Ya no responde preguntas hasta el 1.º del próximo mes (hora de Bogotá). Para seguir antes, TI puede subir ASSISTANT_MONTHLY_BUDGET_USD en el servidor.'
                : 'Al llegar al 100 % dejará de responder hasta el 1.º del próximo mes.')
            ->action('Ver la configuración', route('admin.settings.index'))
            ->line('El uso por día está en Auditoría › Seguridad y con el comando portal:assistant-usage.')
            ->salutation('Portal RYR');
    }
}
