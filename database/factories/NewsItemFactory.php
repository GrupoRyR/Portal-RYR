<?php

namespace Database\Factories;

use App\Models\NewsItem;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsItem>
 */
class NewsItemFactory extends Factory
{
    public function definition(): array
    {
        $link = 'https://news.example.com/'.fake()->unique()->slug(3);

        return [
            'news_source_id' => NewsSource::factory(),
            'title' => fake()->sentence(6),
            'link' => $link,
            'published_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'guid_hash' => hash('sha256', $link),
        ];
    }
}
