<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Prints a fresh VAPID key pair for «Avisos en el celular», as the three
 * lines to paste into the server's .env (docs/despliegue.md §1.22). It
 * writes nothing. A new pair cuts every device subscribed with the old
 * one: they must turn the notices on again from /cuenta.
 */
class GeneratePushKeysCommand extends Command
{
    protected $signature = 'portal:push-keys';

    protected $description = 'Print a fresh VAPID key pair for phone notices (web push)';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('No se pudo crear el par de llaves: '.$e->getMessage());
            $this->line('PHP necesita OpenSSL con curvas P-256 (en Windows, OPENSSL_CONF debe apuntar a un openssl.cnf).');

            return self::FAILURE;
        }

        $this->line('Copie estas tres líneas en el .env del servidor y ejecute php artisan config:cache.');
        $this->line('La llave privada es secreta: no la comparta ni la suba al repositorio.');
        $this->newLine();
        $this->line('PUSH_VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('PUSH_VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('PUSH_VAPID_SUBJECT=mailto:'.$this->contact());

        return self::SUCCESS;
    }

    /** The portal's mailbox, or a placeholder to replace. */
    private function contact(): string
    {
        $address = config('mail.from.address');

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : 'soporte@example.com';
    }
}
