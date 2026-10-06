<?php

namespace App\Policies;

use App\Models\User;

/**
 * Roles page (spatie Role model). `super_admin` comes from the configured
 * emails and `portal_admin` from the APP-PortalAdmin group, both at
 * sign-in, so the only role assigned by hand is `announcements_editor`:
 * super and portal admins may add or remove it, never on an emergency
 * account.
 */
class RolePolicy
{
    public const MANAGERS = ['super_admin', 'portal_admin'];

    public const EDITOR = 'announcements_editor';

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGERS);
    }

    public function assignEditor(User $user, User $target): bool
    {
        return $this->viewAny($user) && ! $target->is_emergency;
    }

    public function removeEditor(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }
}
