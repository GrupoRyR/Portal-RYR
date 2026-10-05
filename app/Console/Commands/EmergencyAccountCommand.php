<?php

namespace App\Console\Commands;

use App\Auth\EmergencyTwoFactor;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Manages the local emergency account (password + TOTP), used to sign in
 * when Microsoft is unavailable. Secrets are only ever shown on this
 * console, once, and nothing is saved until a TOTP code confirms them.
 */
class EmergencyAccountCommand extends Command
{
    public const MIN_PASSWORD_LENGTH = 16;

    protected $signature = 'portal:emergency-account
        {email : Email of the emergency account}
        {--reset-2fa : Replace only the TOTP secret}
        {--disable : Remove the password, 2FA and super_admin, keeping the row}';

    protected $description = 'Create, update, reset the 2FA of or disable the emergency administrator account';

    public function handle(EmergencyTwoFactor $twoFactor): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failWith('El correo no es válido.');
        }

        if ($this->option('reset-2fa') && $this->option('disable')) {
            return $this->failWith('Usa --reset-2fa o --disable, no ambos.');
        }

        if (User::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('azure_oid')->exists()) {
            return $this->failWith('Ese correo pertenece a un usuario de Microsoft; usa otro correo.');
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->get();
        $account = $existing->firstWhere('is_emergency', true);

        if ($account === null && $existing->isNotEmpty()) {
            return $this->failWith('Ese correo pertenece a otro usuario local; usa otro correo.');
        }

        if (($this->option('reset-2fa') || $this->option('disable')) && $account === null) {
            return $this->failWith('No existe una cuenta de emergencia con ese correo.');
        }

        if ($this->option('disable')) {
            return $this->disable($account);
        }

        $password = null;

        if (! $this->option('reset-2fa')) {
            $password = $this->askPassword();

            if ($password === null) {
                return self::FAILURE;
            }
        }

        $secret = $twoFactor->generateSecret();
        $timestep = $this->confirmTwoFactor($twoFactor, $email, $secret);

        if ($timestep === null) {
            return $this->failWith('El código no es válido. No se guardó ningún cambio.');
        }

        DB::transaction(function () use ($account, $email, $password, $secret, $timestep) {
            $user = $account ?? new User;

            $user->forceFill(array_filter([
                'name' => $user->name ?? 'Cuenta de emergencia',
                'email' => $email,
                'is_emergency' => true,
                'password' => $password,
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
                'two_factor_last_timestep' => $timestep,
            ], fn ($value) => $value !== null))->save();

            $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        });

        $this->info('Cuenta de emergencia lista. Guarda la contraseña y el secreto en un lugar seguro.');

        return self::SUCCESS;
    }

    private function askPassword(): ?string
    {
        $password = (string) $this->secret('Contraseña (mínimo '.self::MIN_PASSWORD_LENGTH.' caracteres)');
        $confirmation = (string) $this->secret('Repite la contraseña');

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->failWith('La contraseña debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres. No se guardó ningún cambio.');

            return null;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->failWith('Las contraseñas no coinciden. No se guardó ningún cambio.');

            return null;
        }

        return $password;
    }

    /**
     * Show the secret once and return the time step of a confirming code.
     */
    private function confirmTwoFactor(EmergencyTwoFactor $twoFactor, string $email, string $secret): ?int
    {
        $this->line('Agrega esta cuenta a tu aplicación de autenticación (se muestra una sola vez):');
        $this->line($twoFactor->otpauthUri($email, $secret));
        $this->line('Secreto: '.$secret);

        $code = trim((string) $this->ask('Código de 6 dígitos de la aplicación'));

        return $twoFactor->verify($secret, $code, null);
    }

    private function disable(User $account): int
    {
        DB::transaction(function () use ($account) {
            $account->forceFill([
                'password' => null,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_timestep' => null,
            ])->save();

            if ($account->hasRole('super_admin')) {
                $account->removeRole('super_admin');
            }
        });

        $this->info('Cuenta de emergencia deshabilitada (el registro se conserva).');

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
