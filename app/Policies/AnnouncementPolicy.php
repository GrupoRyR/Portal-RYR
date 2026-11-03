<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * Super and portal admins manage every announcement. Announcements editors
 * (assigned by hand) write only for their own company: the audience must be
 * exactly that company, never «Todas las empresas» nor another company.
 * Nobody deletes an announcement: it is withdrawn instead. Whoever may
 * edit one also sees who read it, when it is mandatory (`viewReads`).
 */
class AnnouncementPolicy
{
    public const MANAGERS = ['super_admin', 'portal_admin'];

    public const EDITOR = 'announcements_editor';

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...self::MANAGERS, self::EDITOR]);
    }

    public function create(User $user): bool
    {
        return self::manages($user) || self::isCompanyEditor($user);
    }

    public function update(User $user, Announcement $announcement): bool
    {
        if (self::manages($user)) {
            return true;
        }

        return self::isCompanyEditor($user)
            && $announcement->audience === Announcement::AUDIENCE_COMPANIES
            && $announcement->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all() === [(int) $user->company_id];
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
        if (! in_array($audience, Announcement::AUDIENCES, true)) {
            return false;
        }

        if (self::manages($user)) {
            return true;
        }

        $ids = array_values(array_unique(array_map('intval', $companyIds)));

        return self::isCompanyEditor($user)
            && $audience === Announcement::AUDIENCE_COMPANIES
            && $ids === [(int) $user->company_id];
    }

    /**
     * Whether the user may see and change every announcement.
     */
    public static function manages(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGERS);
    }

    private static function isCompanyEditor(User $user): bool
    {
        return $user->company_id !== null && $user->hasRole(self::EDITOR);
    }
}
