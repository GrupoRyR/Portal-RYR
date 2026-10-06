<?php

namespace Database\Seeders;

use App\Models\Company;
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
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
            'is_active' => true,
        ]);

        Company::updateOrCreate(['slug' => 'derivados'], [
            'name' => 'Soluciones y Derivados de Colombia S.A.S.',
            'short_name' => 'Derivados',
            'tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468',
            'is_primary_for_tenant' => true,
            'domains' => ['derivadoscol.com'],
            'logo_light_path' => 'brand/logo-derivados.png',
            'color_primary' => '#183F94',
            'color_accent' => '#3E60A8',
            'color_dark' => '#03256C',
            'is_active' => true,
        ]);
    }
}
