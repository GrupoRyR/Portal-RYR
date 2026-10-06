<?php

namespace App\Policies;

use App\Models\App;
use App\Models\User;

/**
 * Catalog apps are managed by the super admin and the portal admins.
 * Nobody deletes an app in this phase: it is hidden instead, so its
 * launches and audit entries keep pointing at it.
 */
class AppPolicy
{
    public const ROLES = ['super_admin', 'portal_admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function view(User $user, App $app): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function update(User $user, App $app): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function delete(User $user, App $app): bool
    {
        return false;
    }
}
