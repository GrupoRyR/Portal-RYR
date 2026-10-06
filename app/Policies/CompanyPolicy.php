<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Companies decide which Microsoft organizations may sign in, so only the
 * super admin manages them (decision 2026-10-05: not portal_admin).
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('super_admin');
    }
}
