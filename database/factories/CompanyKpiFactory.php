<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyKpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyKpi>
 */
class CompanyKpiFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'integration_id' => null,
            'position' => 1,
            'key' => fake()->unique()->slug(2),
            'label' => 'Ventas del mes',
            'value' => 1250000000,
            'unit' => 'COP',
            'goal' => 1500000000,
            'note' => null,
            'period_label' => 'Octubre',
            'as_of' => now(),
            'source_label' => 'SAP Business One',
        ];
    }
}
