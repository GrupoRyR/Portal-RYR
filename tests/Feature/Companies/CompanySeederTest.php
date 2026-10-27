<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_segurex_and_derivados_with_their_real_tenants(): void
    {
        $this->seed(CompanySeeder::class);

        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $this->assertSame('Segurex Latam', $segurex->name);
        $this->assertSame('Segurex', $segurex->short_name);
        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $segurex->tenant_id);
        $this->assertSame(['segurex.com'], $segurex->domains);
        $this->assertSame('#F26522', $segurex->color_primary);
        $this->assertSame('#F26522', $segurex->color_accent);
        $this->assertSame('#58595B', $segurex->color_dark);
        $this->assertSame('brand/logo-segurex.png', $segurex->logo_light_path);
        $this->assertTrue($segurex->is_active);
        $this->assertTrue($segurex->is_primary_for_tenant);
        $this->assertNull($segurex->consent_verified_at);

        $derivados = Company::where('slug', 'derivados')->firstOrFail();
        $this->assertSame('Soluciones y Derivados de Colombia S.A.S.', $derivados->name);
        $this->assertSame('Derivados', $derivados->short_name);
        $this->assertSame('54f53029-e6e2-4b5f-a862-3454ae886468', $derivados->tenant_id);
        $this->assertSame(['derivadoscol.com'], $derivados->domains);
        $this->assertSame('#0B5FA5', $derivados->color_primary);
        $this->assertSame('#0B5FA5', $derivados->color_accent);
        $this->assertSame('#073B66', $derivados->color_dark);
        $this->assertSame('brand/logo-derivados.png', $derivados->logo_light_path);
        $this->assertTrue($derivados->is_active);
        $this->assertTrue($derivados->is_primary_for_tenant);
        $this->assertNull($derivados->consent_verified_at);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(CompanySeeder::class);
        $this->seed(CompanySeeder::class);

        $this->assertSame(2, Company::count());
    }

    public function test_the_database_seeder_includes_the_companies(): void
    {
        $this->seed();

        $this->assertEqualsCanonicalizing(
            ['segurex', 'derivados'],
            Company::pluck('slug')->all(),
        );
    }
}
