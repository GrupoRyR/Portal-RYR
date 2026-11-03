<?php

namespace App\Policies;

use App\Models\ItService;
use App\Models\User;

/**
 * Estado de TI is managed by exactly the people who manage the apps
 * (App\Policies\AppPolicy::ROLES), for every company: the services sit
 * next to the apps' own status and nobody else reports it.
 */
class ItServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function update(User $user, ItService $service): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }

    public function delete(User $user, ItService $service): bool
    {
        return $user->hasAnyRole(AppPolicy::ROLES);
    }
}
