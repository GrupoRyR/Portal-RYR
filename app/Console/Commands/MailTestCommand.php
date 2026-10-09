<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a short test message with the configured mailer, so a deploy can
 * prove that mail works (docs/despliegue.md §1.10). Transport errors are
 * printed as they are: the graph transport keeps them free of secrets.
 */
class MailTestCommand extends Command
{
    protected $signature = 'portal:mail-test {to : Address that receives the test message}';

    protected $description = 'Send a test email with the configured mailer';

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("«{$to}» no es una dirección de correo válida.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $sentAt = now()->timezone('America/Bogota')->format('Y-m-d H:i');

        try {
            Mail::raw(
                "Este es un correo de prueba del Portal RYR, enviado el {$sentAt} (hora de Bogotá).\n\n".
                'Si lo recibió, el envío de correo del portal funciona. No es necesario responder.',
                fn (Message $message) => $message->to($to)->subject('Prueba de correo del Portal RYR'),
            );
        } catch (Throwable $e) {
            $this->error("No se pudo enviar el correo con el mailer «{$mailer}»: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Correo de prueba enviado a {$to} con el mailer «{$mailer}».");

        return self::SUCCESS;
    }
}
