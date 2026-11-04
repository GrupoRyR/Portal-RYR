<?php

namespace Database\Factories;

use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketInput>
 */
class MarketInputFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'label' => fake()->randomElement(['Soda cáustica', 'Cloro', 'Hipoclorito', 'PET', 'HDPE']),
            'unit' => MarketUnit::PesosPerKilo,
            'value' => fake()->randomFloat(4, 1000, 9000),
            'valid_on' => fake()->dateTimeBetween('-60 days', '-1 day')->format('Y-m-d'),
            'source' => 'Cotización de proveedor',
            'created_by' => null,
        ];
    }
}
