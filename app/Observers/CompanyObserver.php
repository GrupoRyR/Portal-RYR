<?php

namespace App\Observers;

use App\Models\Company;
use App\Tenancy\DuplicatePrimaryCompanyException;
use App\Tenancy\TenantDirectory;

class CompanyObserver
{
    public function __construct(private readonly TenantDirectory $directory) {}

    /**
     * At most one company per tenant may be its primary company.
     *
     * Note: this is an application-level check; it is not backed by a
     * database constraint, so concurrent writes could still race.
     */
    public function saving(Company $company): void
    {
        if (! $company->is_primary_for_tenant) {
            return;
        }

        if ($company->exists && ! $company->isDirty(['tenant_id', 'is_primary_for_tenant'])) {
            return;
        }

        $taken = Company::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('is_primary_for_tenant', true)
            ->when($company->exists, fn ($query) => $query->whereKeyNot($company->getKey()))
            ->exists();

        if ($taken) {
            throw DuplicatePrimaryCompanyException::forTenant($company->tenant_id);
        }
    }

    public function saved(Company $company): void
    {
        $this->directory->forget();
    }

    public function deleted(Company $company): void
    {
        $this->directory->forget();
    }
}
