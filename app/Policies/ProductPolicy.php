<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Product catalogs are managed by the super admin and the portal admins,
 * the same people as the app catalog (App\Policies\AppPolicy).
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }
}
