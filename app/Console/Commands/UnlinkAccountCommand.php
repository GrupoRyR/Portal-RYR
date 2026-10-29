<?php

namespace App\Console\Commands;

use App\Auth\LinkedAccounts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Unlinks an account from its person (App\Auth\LinkedAccounts, reason
 * `admin`), for a super administrator until Admin › Usuarios exists.
 *
 * The email is only how the administrator finds the row: nothing is ever
 * linked, unlinked or allowed because two accounts share an email. When
 * several accounts have it (e.g. a B2B guest of the same mailbox),
 * `--id=` picks one. The audit entry has no causer (Auditoría shows
 * «Sistema» and «por un administrador»), and every account the person had
 * gets the notice by mail.
 */
class UnlinkAccountCommand extends Command
{
    protected $signature = 'portal:unlink-account
        {email : Email of the account to unlink}
        {--id= : Id of the account, when several have that email}
        {--force : Unlink without asking for confirmation}';

    protected $description = 'Unlink an account from the other accounts of its person (linked accounts)';

    public function handle(LinkedAccounts $linkedAccounts): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failWith('El correo no es válido.');
        }

        $matches = User::query()->whereRaw('lower(email) = ?', [$email])->with('company')->orderBy('id')->get();

        if ($matches->isEmpty()) {
            return $this->failWith('No hay ninguna cuenta con ese correo.');
        }

        $account = $this->pick($matches);

        if ($account === null) {
            return self::FAILURE;
        }

        $accounts = $linkedAccounts->accountsOf($account);

        if ($accounts->isEmpty()) {
            return $this->failWith('Esa cuenta no está vinculada. No se cambió nada.');
        }

        $this->line('Cuentas de esta persona:');
        $this->table(['Id', 'Empresa', 'Correo', ''], $accounts->map(fn (User $linked) => [
            $linked->id,
            Company::displayName($linked->company),
            $linked->email,
            $linked->is($account) ? 'se desvincula' : '',
        ])->all());

        if ($accounts->count() <= 2) {
            $last = $accounts->first(fn (User $linked) => ! $linked->is($account));
            $this->warn('La persona queda con una sola cuenta: '.self::describe($last).' también se desvincula.');
        }

        if (! $this->option('force') && ! $this->confirm('¿Desvincular la cuenta de '.self::describe($account).'?')) {
            return $this->failWith('No se cambió nada.');
        }

        $linkedAccounts->unlink($account, LinkedAccounts::ADMIN);

        $this->info('Cuenta desvinculada: '.self::describe($account).'.');

        return self::SUCCESS;
    }

    /**
     * The only match, or the one `--id=` names; null (after saying why)
     * otherwise.
     *
     * @param  Collection<int, User>  $matches
     */
    private function pick(Collection $matches): ?User
    {
        $id = $this->option('id');

        if ($id !== null) {
            $account = $matches->first(fn (User $match) => (string) $match->id === trim((string) $id));

            if ($account === null) {
                $this->error("Ninguna cuenta con ese correo tiene el id {$id}.");
            }

            return $account;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error('Hay varias cuentas con ese correo. Elige una con --id=.');
        $this->table(['Id', 'Empresa', 'Correo', 'Tipo'], $matches->map(fn (User $match) => [
            $match->id,
            Company::displayName($match->company),
            $match->email,
            match (true) {
                $match->is_emergency => 'emergencia',
                $match->is_guest === true => 'invitado',
                $match->azure_oid === null => 'local',
                default => 'Microsoft',
            },
        ])->all());

        return null;
    }

    /**
     * «Soluciones y Derivados (edward@derivados.com)».
     */
    private static function describe(?User $account): string
    {
        return Company::displayName($account?->company).' ('.$account?->email.')';
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
