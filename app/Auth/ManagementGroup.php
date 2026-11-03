<?php

namespace App\Auth;

use App\Models\Company;
use App\Models\User;

/**
 * The «Gerencia» group of a company: the security group of that company's
 * own tenant whose object ID a super admin stored in Administración ›
 * Empresas (`companies.management_group_id`, usually APP-PortalGerencia).
 * Its members get the `management` role at sign-in (App\Auth\MicrosoftLogin)
 * and may read the company's management panel (App\Management).
 *
 * Like App\Auth\PortalAdminGroup, a display name proves nothing: only the
 * object ID counts, only for an account of that company signed in to that
 * company's tenant, and only when Microsoft reported the group as a
 * security group (a Microsoft 365 group, any Team, can be created by any
 * user). Without a stored ID nobody qualifies.
 */
final class ManagementGroup
{
    /**
     * Whether the account belonged to its company's group at its last
     * sign-in. With $company, that company must be the account's own.
     */
    public static function contains(User $user, ?Company $company = null): bool
    {
        $company ??= $user->company;

        if ($company === null || $user->is_emergency || $user->tenant_id === null || $user->company_id !== $company->id) {
            return false;
        }

        $groupId = strtolower(trim((string) $company->management_group_id));
        $tenantId = strtolower(trim((string) $company->tenant_id));

        if ($groupId === '' || $tenantId === '') {
            return false;
        }

        return hash_equals($tenantId, strtolower($user->tenant_id))
            && $user->groups()
                ->where('group_id', $groupId)
                ->where('security_group', true)
                ->exists();
    }
}
