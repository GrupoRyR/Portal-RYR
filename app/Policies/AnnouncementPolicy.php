<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;

/**
 * Super and portal admins manage every announcement. Announcements editors
 * (assigned by hand) write only for their own company: the audience must be
 * exactly that company, never «Todas las empresas» nor another company
 * (App\Policies\Rules\CompanyAddressedRules, shared with events). Nobody
 * deletes an announcement: it is withdrawn instead. Whoever may edit one
 * also sees who read it, when it is mandatory (`viewReads`).
 */
class AnnouncementPolicy
{
    public const MANAGERS = CompanyAddressedRules::MANAGERS;

    public const EDITOR = CompanyAddressedRules::EDITOR;

    public function viewAny(User $user): bool
    {
        return CompanyAddressedRules::mayList($user);
    }

    public function create(User $user): bool
    {
        return CompanyAddressedRules::mayCreate($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return CompanyAddressedRules::mayEdit($user, $announcement);
    }

    /**
     * Who read a mandatory announcement and who is missing: the same
     * people who may edit it.
     */
    public function viewReads(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return false;
    }

    /**
     * Whether the user may address an announcement to this audience.
     * Checked on every save, whatever the form showed.
     *
     * @param  array<int|string>  $companyIds
     */
    public function publishTo(User $user, string $audience, array $companyIds): bool
    {
        return CompanyAddressedRules::mayPublishTo($user, $audience, $companyIds, Announcement::AUDIENCES);
    }

    /**
     * Whether the user may see and change every announcement.
     */
    public static function manages(User $user): bool
    {
        return CompanyAddressedRules::manages($user);
    }
}
