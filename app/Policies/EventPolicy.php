<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

/**
 * The same people as announcements (App\Policies\AnnouncementPolicy), with
 * no role of its own: super and portal admins manage every event;
 * announcements editors write only for their own company (the audience
 * must be exactly that company). Nobody deletes an event: it is withdrawn.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...AnnouncementPolicy::MANAGERS, AnnouncementPolicy::EDITOR]);
    }

    public function create(User $user): bool
    {
        return AnnouncementPolicy::manages($user) || self::isCompanyEditor($user);
    }

    public function update(User $user, Event $event): bool
    {
        if (AnnouncementPolicy::manages($user)) {
            return true;
        }

        return self::isCompanyEditor($user)
            && $event->audience === Event::AUDIENCE_COMPANIES
            && $event->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all() === [(int) $user->company_id];
    }

    public function delete(User $user, Event $event): bool
    {
        return false;
    }

    /**
     * Whether the user may address an event to this audience. Checked on
     * every save, whatever the form showed.
     *
     * @param  array<int|string>  $companyIds
     */
    public function publishTo(User $user, string $audience, array $companyIds): bool
    {
        if (! in_array($audience, Event::AUDIENCES, true)) {
            return false;
        }

        if (AnnouncementPolicy::manages($user)) {
            return true;
        }

        $ids = array_values(array_unique(array_map('intval', $companyIds)));

        return self::isCompanyEditor($user)
            && $audience === Event::AUDIENCE_COMPANIES
            && $ids === [(int) $user->company_id];
    }

    private static function isCompanyEditor(User $user): bool
    {
        return $user->company_id !== null && $user->hasRole(AnnouncementPolicy::EDITOR);
    }
}
