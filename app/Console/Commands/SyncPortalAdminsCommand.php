<?php

namespace App\Console\Commands;

use App\Auth\MicrosoftLogin;
use App\Auth\PortalAdminGroup;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Applies the `portal_admin` rule of the sign-in (App\Auth\PortalAdminGroup)
 * to every user now, from the groups stored at their last sign-in, instead
 * of waiting for each one to sign in again. Run once after setting
 * PORTAL_ADMIN_GROUP_ID and PORTAL_ADMIN_TENANT_ID; safe to run again.
 */
class SyncPortalAdminsCommand extends Command
{
    protected $signature = 'portal:sync-portal-admins';

    protected $description = 'Grant or remove portal_admin from the pinned Microsoft group';

    public function handle(): int
    {
        if (! PortalAdminGroup::isConfigured()) {
            $this->error('Falta PORTAL_ADMIN_GROUP_ID o PORTAL_ADMIN_TENANT_ID en .env; no se cambió nada.');

            return self::FAILURE;
        }

        $role = Role::findOrCreate(MicrosoftLogin::PORTAL_ADMIN, 'web');
        $removed = 0;
        $added = 0;

        User::query()->where('is_emergency', false)->with('roles')->each(function (User $user) use ($role, &$removed, &$added) {
            $shouldHave = PortalAdminGroup::contains($user);

            if ($shouldHave && ! $user->hasRole($role)) {
                $user->assignRole($role);
                $added++;
                $this->line("+ {$user->email}");
            } elseif (! $shouldHave && $user->hasRole($role)) {
                $user->removeRole($role);
                $removed++;
                $this->line("- {$user->email}");
            }
        });

        $this->info(sprintf(
            'portal_admin: %d %s, %d %s.',
            $removed,
            $removed === 1 ? 'quitado' : 'quitados',
            $added,
            $added === 1 ? 'agregado' : 'agregados',
        ));

        return self::SUCCESS;
    }
}
