<?php

namespace App\Policies;

use App\Auth\ManagementGroup;
use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\User;

/**
 * `viewManagementPanel` (App\Management\ManagementPanel::ABILITY): the
 * management figures of a company are for its «Gerencia» only.
 *
 * True only for a Microsoft account of that same, active company that has
 * the `management` role and, at its last sign-in, belonged to the
 * company's current «Gerencia» security group (App\Auth\ManagementGroup).
 * The role is aligned at every sign-in; checking the group again here means
 * that changing or clearing the group in Administración › Empresas closes
 * the panel at once, without waiting for the next sign-in. No admin role
 * opens it.
 */
final class ManagementPanelPolicy
{
    /**
     * Without a company, the account's own.
     */
    public function view(User $user, ?Company $company = null): bool
    {
        $company ??= $user->company;

        return $company !== null
            && $company->is_active
            && ! $user->is_emergency
            && $user->company_id === $company->id
            && $user->hasRole(MicrosoftLogin::MANAGEMENT)
            && ManagementGroup::contains($user, $company);
    }
}
