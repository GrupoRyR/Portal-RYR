<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompanyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_id_is_trimmed_and_lowercased(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => '  97CD024C-CD7A-419B-B385-F47B5F5816C8 ',
        ]);

        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $company->fresh()->tenant_id);
    }

    public function test_domains_are_trimmed_lowercased_and_deduplicated(): void
    {
        $company = Company::factory()->create([
            'domains' => [' Segurex.COM ', '@segurex.com', 'Segurex.co', ''],
        ]);

        $this->assertSame(['segurex.com', 'segurex.co'], $company->fresh()->domains);
    }

    public function test_attributes_are_cast(): void
    {
        $company = Company::factory()->create([
            'quick_links' => [['label' => 'SharePoint', 'url' => 'https://example.com']],
            'consent_verified_at' => '2026-10-05 08:00:00',
        ])->fresh();

        $this->assertIsArray($company->domains);
        $this->assertSame([['label' => 'SharePoint', 'url' => 'https://example.com']], $company->quick_links);
        $this->assertIsBool($company->is_active);
        $this->assertIsBool($company->is_primary_for_tenant);
        $this->assertInstanceOf(Carbon::class, $company->consent_verified_at);
    }

    public function test_new_companies_are_active_and_primary_by_default(): void
    {
        $company = Company::create([
            'name' => 'Acme S.A.S.',
            'short_name' => 'Acme',
            'slug' => 'acme',
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'domains' => ['acme.com'],
            'color_primary' => '#112233',
            'color_accent' => '#445566',
            'color_dark' => '#000000',
        ])->fresh();

        $this->assertTrue($company->is_active);
        $this->assertTrue($company->is_primary_for_tenant);
        $this->assertNull($company->quick_links);
        $this->assertNull($company->consent_verified_at);
    }

    public function test_active_scope_excludes_inactive_companies(): void
    {
        $active = Company::factory()->create();
        Company::factory()->inactive()->create();

        $this->assertSame([$active->id], Company::active()->pluck('id')->all());
    }

    public function test_owns_email_domain_matches_case_insensitively(): void
    {
        $company = Company::factory()->create(['domains' => ['segurex.com']]);

        $this->assertTrue($company->ownsEmailDomain('jhon@segurex.com'));
        $this->assertTrue($company->ownsEmailDomain('  Jhon@SEGUREX.com '));
    }

    public function test_owns_email_domain_rejects_lookalikes_and_invalid_emails(): void
    {
        $company = Company::factory()->create(['domains' => ['segurex.com']]);

        $this->assertFalse($company->ownsEmailDomain('x@notsegurex.com'));
        $this->assertFalse($company->ownsEmailDomain('x@segurex.com.evil.io'));
        $this->assertFalse($company->ownsEmailDomain('x@mail.segurex.com'));
        $this->assertFalse($company->ownsEmailDomain('segurex.com'));
        $this->assertFalse($company->ownsEmailDomain('x@'));
        $this->assertFalse($company->ownsEmailDomain(''));
    }
}
