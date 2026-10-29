<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;

/**
 * The same people and rules as announcements (App\Policies\Rules\CompanyAddressedRules),
 * with no role of its own: super and portal admins manage every event;
 * announcements editors write only for their own company (the audience
 * must be exactly that company). Nobody deletes an event: it is withdrawn.
 */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return CompanyAddressedRules::mayList($user);
    }

    public function create(User $user): bool
    {
        return CompanyAddressedRules::mayCreate($user);
    }

    public function update(User $user, Event $event): bool
    {
        return CompanyAddressedRules::mayEdit($user, $event);
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
        return CompanyAddressedRules::mayPublishTo($user, $audience, $companyIds, Event::AUDIENCES);
    }
}
