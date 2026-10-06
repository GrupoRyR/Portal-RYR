<?php

namespace App\Policies;

use App\Models\AppCategory;
use App\Models\User;

/**
 * Categories are managed by the super admin and the portal admins. Only
 * empty categories can be deleted (App\Livewire\Admin\CategoryManager).
 */
class AppCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function update(User $user, AppCategory $category): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function delete(User $user, AppCategory $category): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }
}
