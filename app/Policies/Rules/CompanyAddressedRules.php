<?php

namespace App\Policies\Rules;

use App\Models\Announcement;
use App\Models\Event;
use App\Models\User;

/**
 * Who manages content addressed to companies (announcements and events,
 * App\Models\Concerns\AddressedToCompanies): super and portal admins manage
 * all of it; announcements editors write only for their own company, so the
 * audience must be exactly that company, never «Todas las empresas» nor
 * another company. App\Policies\AnnouncementPolicy and EventPolicy call it.
 */
final class CompanyAddressedRules
{
    public const MANAGERS = ['super_admin', 'portal_admin'];

    public const EDITOR = 'announcements_editor';

    /**
     * Whether the user may see and change all of it.
     */
    public static function manages(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGERS);
    }

    public static function isCompanyEditor(User $user): bool
    {
        return $user->company_id !== null && $user->hasRole(self::EDITOR);
    }

    public static function mayList(User $user): bool
    {
        return $user->hasAnyRole([...self::MANAGERS, self::EDITOR]);
    }

    public static function mayCreate(User $user): bool
    {
        return self::manages($user) || self::isCompanyEditor($user);
    }

    public static function mayEdit(User $user, Announcement|Event $item): bool
    {
        if (self::manages($user)) {
            return true;
        }

        return self::isCompanyEditor($user)
            && $item->audience === $item::AUDIENCE_COMPANIES
            && $item->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all() === [(int) $user->company_id];
    }

    /**
     * Whether the user may address it to this audience. Checked on every
     * save, whatever the form showed.
     *
     * @param  list<string>  $audiences  The model's AUDIENCES.
     * @param  array<int|string>  $companyIds
     */
    public static function mayPublishTo(User $user, string $audience, array $companyIds, array $audiences): bool
    {
        if (! in_array($audience, $audiences, true)) {
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
}
