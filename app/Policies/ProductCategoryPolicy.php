<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

/**
 * Product categories are managed by the same people as the products
 * (App\Policies\ProductPolicy).
 */
class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }
}
