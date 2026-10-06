<?php

namespace Database\Factories;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Monitor '.fake()->unique()->word(),
            'abilities' => [Integration::CATALOG_READ],
            'allowed_ips' => [],
        ];
    }
}
