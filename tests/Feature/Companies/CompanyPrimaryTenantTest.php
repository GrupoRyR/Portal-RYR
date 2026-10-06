<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Tenancy\DuplicatePrimaryCompanyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPrimaryTenantTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'aaaaaaaa-0000-0000-0000-000000000001';

    public function test_a_second_primary_company_for_the_same_tenant_is_rejected(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();

        $this->expectException(DuplicatePrimaryCompanyException::class);

        Company::factory()->forTenant(strtoupper(self::TENANT))->create();
    }

    public function test_promoting_a_secondary_company_while_another_is_primary_is_rejected(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();
        $secondary = Company::factory()->forTenant(self::TENANT, primary: false)->create();

        try {
            $secondary->update(['is_primary_for_tenant' => true]);
            $this->fail('Expected a DuplicatePrimaryCompanyException.');
        } catch (DuplicatePrimaryCompanyException) {
            $this->assertFalse($secondary->fresh()->is_primary_for_tenant);
        }
    }

    public function test_moving_a_primary_company_onto_a_tenant_that_has_one_is_rejected(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();
        $other = Company::factory()->forTenant('bbbbbbbb-0000-0000-0000-000000000002')->create();

        $this->expectException(DuplicatePrimaryCompanyException::class);

        $other->update(['tenant_id' => self::TENANT]);
    }

    public function test_secondary_companies_and_resaving_the_primary_are_allowed(): void
    {
        $primary = Company::factory()->forTenant(self::TENANT)->create();
        Company::factory()->forTenant(self::TENANT, primary: false)->create();
        Company::factory()->forTenant(self::TENANT, primary: false)->create();

        $primary->update(['name' => 'Renamed']);

        $this->assertSame(1, Company::where('tenant_id', self::TENANT)->where('is_primary_for_tenant', true)->count());
        $this->assertSame(3, Company::where('tenant_id', self::TENANT)->count());
    }

    public function test_the_primary_role_can_move_once_the_old_primary_is_demoted(): void
    {
        $old = Company::factory()->forTenant(self::TENANT)->create();
        $new = Company::factory()->forTenant(self::TENANT, primary: false)->create();

        $old->update(['is_primary_for_tenant' => false]);
        $new->update(['is_primary_for_tenant' => true]);

        $this->assertTrue($new->fresh()->is_primary_for_tenant);
    }

    public function test_the_exception_message_is_in_spanish(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();

        try {
            Company::factory()->forTenant(self::TENANT)->create();
            $this->fail('Expected a DuplicatePrimaryCompanyException.');
        } catch (DuplicatePrimaryCompanyException $e) {
            $this->assertStringContainsString('empresa principal', $e->getMessage());
        }
    }
}
