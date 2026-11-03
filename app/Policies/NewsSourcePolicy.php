<?php

namespace App\Policies;

use App\Models\NewsSource;
use App\Models\User;

/**
 * News sources make the server request third-party URLs every hour, so,
 * like the API tokens of Integraciones (AdminSections::INTEGRATIONS), only
 * super admins manage them. App\Livewire\Admin\NewsManager checks again on
 * every action and render.
 */
class NewsSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return AdminSections::manageIntegrations($user);
    }

    public function create(User $user): bool
    {
        return AdminSections::manageIntegrations($user);
    }

    public function update(User $user, NewsSource $source): bool
    {
        return AdminSections::manageIntegrations($user);
    }

    public function delete(User $user, NewsSource $source): bool
    {
        return AdminSections::manageIntegrations($user);
    }
}
