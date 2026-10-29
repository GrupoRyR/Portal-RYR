<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Knows which Microsoft tenants may sign in and which company a signed-in
 * user belongs to. Only active companies count.
 */
class TenantDirectory
{
    public const CACHE_KEY = 'tenancy:allowed_tenant_ids';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Distinct lowercase tenant IDs of the active companies. Cached forever;
     * {@see CompanyObserver} forgets it whenever a company changes.
     *
     * @return list<string>
     */
    public function allowedTenantIds(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, fn () => Company::active()
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(fn (string $tenantId) => self::normalize($tenantId))
            ->unique()
            ->values()
            ->all());
    }

    public function isAllowed(?string $tenantId): bool
    {
        $tenantId = self::normalize($tenantId);

        return $tenantId !== '' && in_array($tenantId, $this->allowedTenantIds(), true);
    }

    /**
     * The company of a tenant's user, only when it is active. A tenant
     * with a single company gets it whatever the email. When several
     * companies share the tenant, the email domain decides, otherwise the
     * tenant's primary company, among all of the tenant's companies:
     * active or not, so that the users of a deactivated company never land
     * in another one. Returns null rather than guessing.
     */
    public function resolveCompany(string $tenantId, ?string $email): ?Company
    {
        $tenantId = self::normalize($tenantId);

        if ($tenantId === '') {
            return null;
        }

        $companies = Company::query()->where('tenant_id', $tenantId)->get();

        $company = $companies->count() === 1
            ? $companies->first()
            : self::byDomain($companies, $email) ?? self::primary($companies);

        return $company?->is_active ? $company : null;
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private static function byDomain(Collection $companies, ?string $email): ?Company
    {
        $owners = $email === null
            ? collect()
            : $companies->filter(fn (Company $company) => $company->ownsEmailDomain($email));

        return $owners->count() === 1 ? $owners->first() : null;
    }

    /**
     * @param  Collection<int, Company>  $companies
     */
    private static function primary(Collection $companies): ?Company
    {
        $primaries = $companies->where('is_primary_for_tenant', true);

        return $primaries->count() === 1 ? $primaries->first() : null;
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private static function normalize(?string $tenantId): string
    {
        return strtolower(trim((string) $tenantId));
    }
}
