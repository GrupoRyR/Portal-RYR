<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_category_id' => fn (array $attributes) => ProductCategory::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'name' => Str::ucfirst(fake()->unique()->words(3, true)),
            'summary' => fake()->sentence(),
            'photo_path' => null,
            'link_url' => null,
            'position' => fake()->numberBetween(1, 100),
            'published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published' => true]);
    }
}
