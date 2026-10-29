<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Tenancy\TenantDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_TENANT = 'aaaaaaaa-0000-0000-0000-000000000001';

    private function directory(): TenantDirectory
    {
        return $this->app->make(TenantDirectory::class);
    }

    /**
     * Run the callback and return how many queries it sent to the database.
     */
    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        return $count;
    }

    public function test_it_is_a_singleton(): void
    {
        $this->assertSame($this->directory(), $this->directory());
    }

    public function test_allowed_tenants_are_the_distinct_tenants_of_active_companies(): void
    {
        Company::factory()->forTenant('11111111-1111-1111-1111-111111111111')->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['a.com'])->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['b.com'], primary: false)->create();
        Company::factory()->inactive()->forTenant('22222222-2222-2222-2222-222222222222')->create();

        $this->assertEqualsCanonicalizing(
            ['11111111-1111-1111-1111-111111111111', self::SHARED_TENANT],
            $this->directory()->allowedTenantIds(),
        );
    }

    public function test_is_allowed_is_case_insensitive_and_rejects_empty_values(): void
    {
        Company::factory()->forTenant('97cd024c-cd7a-419b-b385-f47b5f5816c8')->create();
        Company::factory()->inactive()->forTenant('22222222-2222-2222-2222-222222222222')->create();

        $directory = $this->directory();

        $this->assertTrue($directory->isAllowed('97cd024c-cd7a-419b-b385-f47b5f5816c8'));
        $this->assertTrue($directory->isAllowed(' 97CD024C-CD7A-419B-B385-F47B5F5816C8 '));
        $this->assertFalse($directory->isAllowed('22222222-2222-2222-2222-222222222222'));
        $this->assertFalse($directory->isAllowed('33333333-3333-3333-3333-333333333333'));
        $this->assertFalse($directory->isAllowed(null));
        $this->assertFalse($directory->isAllowed(''));
        $this->assertFalse($directory->isAllowed('   '));
    }

    public function test_allowed_tenants_are_cached_after_the_first_call(): void
    {
        Company::factory()->create();
        $directory = $this->directory();

        $this->assertGreaterThan(0, $this->countQueries(fn () => $directory->allowedTenantIds()));
        $this->assertSame(0, $this->countQueries(fn () => $directory->allowedTenantIds()));
        $this->assertSame(0, $this->countQueries(fn () => $directory->isAllowed('x')));
    }

    public function test_forget_drops_the_cache(): void
    {
        $company = Company::factory()->create();
        $directory = $this->directory();
        $directory->allowedTenantIds();

        // Bypass model events so only an explicit forget() can refresh the list.
        DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
        $this->assertSame([$company->tenant_id], $directory->allowedTenantIds());

        $directory->forget();
        $this->assertSame([], $directory->allowedTenantIds());
    }

    public function test_creating_a_company_invalidates_the_cache(): void
    {
        $directory = $this->directory();
        $this->assertSame([], $directory->allowedTenantIds());

        $company = Company::factory()->create();

        $this->assertSame([$company->tenant_id], $directory->allowedTenantIds());
    }

    public function test_updating_a_company_tenant_invalidates_the_cache(): void
    {
        $company = Company::factory()->forTenant('11111111-1111-1111-1111-111111111111')->create();
        $directory = $this->directory();
        $directory->allowedTenantIds();

        $company->update(['tenant_id' => '44444444-4444-4444-4444-444444444444']);

        $this->assertSame(['44444444-4444-4444-4444-444444444444'], $directory->allowedTenantIds());
    }

    public function test_deactivating_a_company_invalidates_the_cache(): void
    {
        $company = Company::factory()->create();
        $directory = $this->directory();
        $this->assertTrue($directory->isAllowed($company->tenant_id));

        $company->update(['is_active' => false]);

        $this->assertFalse($directory->isAllowed($company->tenant_id));
    }

    public function test_deleting_a_company_invalidates_the_cache(): void
    {
        $company = Company::factory()->create();
        $directory = $this->directory();
        $this->assertTrue($directory->isAllowed($company->tenant_id));

        $company->delete();

        $this->assertFalse($directory->isAllowed($company->tenant_id));
    }

    public function test_seeding_invalidates_the_cache_even_without_model_events(): void
    {
        $directory = $this->directory();
        $this->assertSame([], $directory->allowedTenantIds());

        // DatabaseSeeder mutes model events, so the seeder must forget explicitly.
        $this->seed();

        $this->assertTrue($directory->isAllowed('97cd024c-cd7a-419b-b385-f47b5f5816c8'));
        $this->assertTrue($directory->isAllowed('54f53029-e6e2-4b5f-a862-3454ae886468'));
    }

    public function test_resolve_company_returns_the_only_company_of_a_tenant(): void
    {
        $company = Company::factory()->forTenant('11111111-1111-1111-1111-111111111111', ['segurex.com'])->create();

        // A guest from another domain still belongs to the tenant's company.
        $resolved = $this->directory()->resolveCompany('11111111-1111-1111-1111-111111111111', 'guest@derivadoscol.com');

        $this->assertTrue($company->is($resolved));
        $this->assertTrue($company->is($this->directory()->resolveCompany('11111111-1111-1111-1111-111111111111', null)));
    }

    public function test_resolve_company_matches_mixed_case_tenant_ids(): void
    {
        $company = Company::factory()->forTenant('97cd024c-cd7a-419b-b385-f47b5f5816c8')->create();

        $this->assertTrue($company->is(
            $this->directory()->resolveCompany('97CD024C-CD7A-419B-B385-F47B5F5816C8', 'jhon@segurex.com'),
        ));
    }

    public function test_resolve_company_picks_the_shared_tenant_company_by_email_domain(): void
    {
        $primary = Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        $secondary = Company::factory()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();

        $directory = $this->directory();

        $this->assertTrue($secondary->is($directory->resolveCompany(self::SHARED_TENANT, 'ana@Second.com')));
        $this->assertTrue($primary->is($directory->resolveCompany(self::SHARED_TENANT, 'luis@main.com')));
    }

    public function test_resolve_company_falls_back_to_the_primary_company_of_a_shared_tenant(): void
    {
        $primary = Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();

        $directory = $this->directory();

        $this->assertTrue($primary->is($directory->resolveCompany(self::SHARED_TENANT, 'x@second.com.evil.io')));
        $this->assertTrue($primary->is($directory->resolveCompany(self::SHARED_TENANT, null)));
    }

    public function test_resolve_company_returns_null_when_a_shared_tenant_has_no_active_primary(): void
    {
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['third.com'], primary: false)->create();

        $this->assertNull($this->directory()->resolveCompany(self::SHARED_TENANT, 'x@other.com'));
    }

    public function test_resolve_company_returns_null_for_unknown_tenants(): void
    {
        Company::factory()->create();

        $this->assertNull($this->directory()->resolveCompany('99999999-9999-9999-9999-999999999999', 'x@segurex.com'));
        $this->assertNull($this->directory()->resolveCompany('', 'x@segurex.com'));
    }

    public function test_resolve_company_ignores_inactive_companies(): void
    {
        $inactive = Company::factory()->inactive()->forTenant('11111111-1111-1111-1111-111111111111')->create();

        $this->assertNull($this->directory()->resolveCompany($inactive->tenant_id, 'x@'.$inactive->domains[0]));
    }

    public function test_resolve_company_skips_an_inactive_company_on_a_shared_tenant(): void
    {
        $primary = Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();

        $directory = $this->directory();

        $this->assertTrue($primary->is($directory->resolveCompany(self::SHARED_TENANT, 'x@main.com')));
        // A guest from a domain no company of the tenant owns.
        $this->assertTrue($primary->is($directory->resolveCompany(self::SHARED_TENANT, 'x@other.com')));
    }

    /**
     * Two companies share a tenant and one is deactivated: its users must
     * not land in the other one, even when that is the tenant's only
     * active company.
     */
    public function test_resolve_company_refuses_a_user_of_an_inactive_company_on_a_shared_tenant(): void
    {
        Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();

        $this->assertNull($this->directory()->resolveCompany(self::SHARED_TENANT, 'ana@Second.com'));
    }

    public function test_resolve_company_refuses_a_user_of_an_inactive_company_among_several_active_ones(): void
    {
        Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->forTenant(self::SHARED_TENANT, ['second.com'], primary: false)->create();
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['third.com'], primary: false)->create();

        $this->assertNull($this->directory()->resolveCompany(self::SHARED_TENANT, 'ana@third.com'));
    }

    /**
     * The deactivated company is the tenant's primary: users the primary
     * fallback places there (no company owns their domain) must not land
     * in the tenant's only active company either.
     */
    public function test_resolve_company_refuses_users_of_a_deactivated_primary(): void
    {
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['segurex.com'])->create();
        $sdc = Company::factory()->forTenant(self::SHARED_TENANT, ['sdc.com'], primary: false)->create();

        $directory = $this->directory();

        $this->assertNull($directory->resolveCompany(self::SHARED_TENANT, 'x@gmail.com'));
        $this->assertNull($directory->resolveCompany(self::SHARED_TENANT, 'x@segurex.onmicrosoft.com'));
        $this->assertNull($directory->resolveCompany(self::SHARED_TENANT, null));
        $this->assertTrue($sdc->is($directory->resolveCompany(self::SHARED_TENANT, 'ana@sdc.com')));
    }

    public function test_resolve_company_keeps_a_domain_listed_by_an_active_company_too(): void
    {
        // Company::domainOwner() keeps domains unique, but old rows may not.
        $primary = Company::factory()->forTenant(self::SHARED_TENANT, ['main.com'])->create();
        Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['main.com'], primary: false)->create();

        $this->assertTrue($primary->is($this->directory()->resolveCompany(self::SHARED_TENANT, 'ana@main.com')));
    }
}
