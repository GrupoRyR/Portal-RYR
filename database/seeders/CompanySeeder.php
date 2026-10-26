<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Tenancy\TenantDirectory;
use Illuminate\Database\Seeder;

/**
 * Initial companies of the group (brief, sections 3 and 4b). Idempotent:
 * re-running it updates the rows matched by slug instead of duplicating them.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::updateOrCreate(['slug' => 'segurex'], [
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
            'is_primary_for_tenant' => true,
            'domains' => ['segurex.com'],
            'logo_light_path' => 'brand/logo-segurex.png',
            // Official brand manual SGX/SDC (2026-10-23).
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
            'color_ink' => '#943E15',
            'color_text' => '#58595B',
            'color_ink_dark_mode' => '#F26522',
            'color_text_dark_mode' => '#A8A9AA',
            'is_active' => true,
        ]);

        Company::updateOrCreate(['slug' => 'derivados'], [
            'name' => 'Soluciones y Derivados de Colombia S.A.S.',
            'short_name' => 'Derivados',
            'tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468',
            'is_primary_for_tenant' => true,
            'domains' => ['derivadoscol.com'],
            'logo_light_path' => 'brand/logo-derivados.png',
            'color_primary' => '#0B5FA5',
            'color_accent' => '#0B5FA5',
            'color_dark' => '#073B66',
            'color_button' => '#0B5FA5',
            'color_ink' => '#073B66',
            'color_text' => '#073B66',
            'color_ink_dark_mode' => '#4D8ABD',
            'color_text_dark_mode' => '#4D8ABD',
            'is_active' => true,
        ]);

        // DatabaseSeeder mutes model events, so the observer that normally
        // drops the allowed-tenants cache does not run here.
        app(TenantDirectory::class)->forget();
    }
}
