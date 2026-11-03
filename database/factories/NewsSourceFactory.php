<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsSource>
 */
class NewsSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'tab' => NewsSource::TAB_COLOMBIA,
            'name' => 'Noticias '.fake()->unique()->word(),
            'url' => 'https://news.example.com/'.fake()->unique()->slug(2).'.xml',
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function tab(string $tab): static
    {
        return $this->state(fn () => ['tab' => $tab]);
    }
}
