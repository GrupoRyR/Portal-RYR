<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;

/**
 * Estado de TI (decision 2026-11-03): super and portal admins manage the
 * services of every company; announcements editors manage only those of
 * their own company, like their announcements
 * (App\Policies\Rules\CompanyAddressedRules). An editor without a company
 * manages none.
 */
class ItServicePolicy
{
    public function viewAny(User $user): bool
    {
        return CompanyAddressedRules::mayList($user);
    }

    public function create(User $user): bool
    {
        return CompanyAddressedRules::mayCreate($user);
    }

    /**
     * Whether the user may open and change the services of this company.
     * App\Livewire\Admin\ItServiceManager checks it for the selected
     * company on every action and every render.
     */
    public function manageCompany(User $user, Company $company): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $company->getKey());
    }

    public function update(User $user, ItService $service): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $service->company_id);
    }

    public function delete(User $user, ItService $service): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $service->company_id);
    }
}
