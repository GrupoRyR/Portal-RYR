<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Contracts\Cache\Repository as Cache;

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
     * The active company of a tenant. When several companies share the
     * tenant, the email domain decides; otherwise the tenant's primary
     * company is used. Returns null rather than guessing.
     */
    public function resolveCompany(string $tenantId, ?string $email): ?Company
    {
        $tenantId = self::normalize($tenantId);

        if ($tenantId === '') {
            return null;
        }

        $companies = Company::active()->where('tenant_id', $tenantId)->get();

        if ($companies->count() <= 1) {
            return $companies->first();
        }

        if ($email !== null) {
            $byDomain = $companies->filter(fn (Company $company) => $company->ownsEmailDomain($email));

            if ($byDomain->count() === 1) {
                return $byDomain->first();
            }
        }

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
