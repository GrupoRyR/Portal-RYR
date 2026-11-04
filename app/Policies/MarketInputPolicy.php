<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\MarketInput;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;

/**
 * Administración › Mercados (odd/tasks/mercados-o5.md M4): the inputs a
 * company's band shows (`companies.market_codes`) and its prices typed by
 * hand. Like Estado de TI (App\Policies\ItServicePolicy): super and portal
 * admins manage every company; announcements editors only their own
 * (App\Policies\Rules\CompanyAddressedRules). An editor without a company
 * manages none.
 */
class MarketInputPolicy
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
     * Whether the user may open this company's markets, choose its inputs
     * and change its prices. App\Livewire\Admin\MarketManager checks it for
     * the selected company on every action and every render.
     */
    public function manageCompany(User $user, Company $company): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $company->getKey());
    }

    public function update(User $user, MarketInput $input): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $input->company_id);
    }

    public function delete(User $user, MarketInput $input): bool
    {
        return CompanyAddressedRules::managesCompany($user, (int) $input->company_id);
    }
}
