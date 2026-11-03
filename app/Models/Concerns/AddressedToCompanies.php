<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * Content addressed to every company or to chosen ones, withdrawn instead of
 * deleted, and audited: announcements and events. What differs stays in the
 * model: its pivot table (`companies()`), what «published» means
 * (`scopeInStatus()`; an event is also not over yet) and its audit names.
 *
 * The model defines AUDIT_LOG (activity log name), AUDIT_EVENT_PREFIX
 * («announcement», «event»: «{prefix}.withdrawn»), AUDIENCE_ALL,
 * AUDIENCE_COMPANIES, STATUS_PUBLISHED, `companies()` and `scopeInStatus()`.
 */
trait AddressedToCompanies
{
    /**
     * Writes a named audit entry; the causer is the signed-in user, if any.
     *
     * @param  array<string, mixed>  $properties
     */
    public function audit(string $event, array $properties = []): void
    {
        activity(self::AUDIT_LOG)
            ->performedOn($this)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }

    /**
     * Off the home screen at once. Nothing is deleted; {@see republish()}
     * brings it back.
     */
    public function withdraw(): void
    {
        if ($this->withdrawn_at !== null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => now()])->save();
        $this->audit(self::AUDIT_EVENT_PREFIX.'.withdrawn');
    }

    public function republish(): void
    {
        if ($this->withdrawn_at === null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => null])->save();
        $this->audit(self::AUDIT_EVENT_PREFIX.'.republished');
    }

    public function isForAllCompanies(): bool
    {
        return $this->audience === self::AUDIENCE_ALL;
    }

    /**
     * What a user sees right now: published (the model's `scopeInStatus()`)
     * and addressed to their company. Users without a company (emergency
     * accounts) see none.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->company_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->inStatus(self::STATUS_PUBLISHED)->forCompanyId((int) $user->company_id);
    }

    /**
     * Addressed to the company, including what is for every company,
     * whatever its status.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForCompanyId(Builder $query, int $companyId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('audience', self::AUDIENCE_ALL)
            ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($companyId)));
    }

    /**
     * What the user may edit (App\Policies\Rules\CompanyAddressedRules): all
     * of it for admins; for an announcements editor, what is addressed to
     * exactly their own company; nothing for anybody else.
     *
     * @param  Builder<static>  $query
     */
    public function scopeManageableBy(Builder $query, User $user): void
    {
        if (CompanyAddressedRules::manages($user)) {
            return;
        }

        if (! CompanyAddressedRules::isCompanyEditor($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $companyId = (int) $user->company_id;

        $query->where('audience', self::AUDIENCE_COMPANIES)
            ->whereHas('companies', fn (Builder $query) => $query->whereKey($companyId))
            ->whereDoesntHave('companies', fn (Builder $query) => $query->whereKeyNot($companyId));
    }

    /**
     * Short names (or names) of the companies, alphabetical: what the
     * «companies_changed» audit entry keeps.
     *
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    protected function companyNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Company::query()->whereKey($ids)->get(['id', 'name', 'short_name'])
            ->map(fn (Company $company) => $company->short_name ?: $company->name)
            ->sort()
            ->values()
            ->all();
    }
}
