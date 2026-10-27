<?php

namespace App\Auth;

use App\Models\User;

/**
 * The Microsoft group whose members are portal admins: pinned by object ID
 * (config('portal.admin_group_id')) inside one trusted tenant
 * (config('portal.admin_tenant_id')), the Segurex one.
 *
 * A display name proves nothing: anyone allowed to create a group or a Team
 * can call it APP-PortalAdmin, and every onboarded tenant has its own admins.
 * Groups are read from the tenant the user signed in to, so only a sign-in
 * to the trusted tenant counts. Without both settings nobody qualifies.
 */
final class PortalAdminGroup
{
    public static function isConfigured(): bool
    {
        return self::groupId() !== '' && self::tenantId() !== '';
    }

    /**
     * Whether the user belonged to the pinned group at their last sign-in
     * to the trusted tenant.
     */
    public static function contains(User $user): bool
    {
        if (! self::isConfigured() || $user->is_emergency || $user->tenant_id === null) {
            return false;
        }

        return hash_equals(self::tenantId(), $user->tenant_id)
            && $user->groups()->where('group_id', self::groupId())->exists();
    }

    private static function groupId(): string
    {
        return strtolower(trim((string) config('portal.admin_group_id')));
    }

    private static function tenantId(): string
    {
        return strtolower(trim((string) config('portal.admin_tenant_id')));
    }
}
