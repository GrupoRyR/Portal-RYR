<?php

namespace Database\Factories;

use App\Models\AppCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppCategory>
 */
class AppCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name),
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
