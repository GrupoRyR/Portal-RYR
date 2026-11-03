<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ItService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItService>
 */
class ItServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => Str::ucfirst(fake()->unique()->words(2, true)),
            'status' => ItService::STATUS_OPERATIONAL,
            'message' => null,
            'position' => fake()->numberBetween(1, 100),
        ];
    }

    public function degraded(?string $message = null): static
    {
        return $this->state(fn () => ['status' => ItService::STATUS_DEGRADED, 'message' => $message]);
    }

    public function down(?string $message = null): static
    {
        return $this->state(fn () => ['status' => ItService::STATUS_DOWN, 'message' => $message]);
    }
}
