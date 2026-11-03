<?php

namespace Database\Factories;

use App\Models\MarketRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketRate>
 */
class MarketRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => MarketRate::TRM,
            'value' => fake()->randomFloat(4, 3000, 4500),
            'valid_from' => fake()->unique()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'valid_until' => null,
            'source' => 'datos.gov.co',
            'fetched_at' => now(),
        ];
    }

    /**
     * The TRM, from the official dataset.
     */
    public function trm(): static
    {
        return $this->state(fn () => ['code' => MarketRate::TRM, 'source' => 'datos.gov.co']);
    }

    /**
     * The euro in pesos, from the ECB rate.
     */
    public function eur(): static
    {
        return $this->state(fn () => ['code' => MarketRate::EUR, 'source' => 'ecb.europa.eu']);
    }
}
