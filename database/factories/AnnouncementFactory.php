<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published announcement for every company unless a state says otherwise.
 *
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'body' => fake()->sentence(14),
            'image_path' => null,
            'audience' => Announcement::AUDIENCE_ALL,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'is_pinned' => false,
        ];
    }

    /**
     * Only for the given companies.
     */
    public function forCompanies(Company ...$companies): static
    {
        return $this
            ->state(fn () => ['audience' => Announcement::AUDIENCE_COMPANIES])
            ->afterCreating(fn (Announcement $announcement) => $announcement->companies()->attach(collect($companies)->pluck('id')));
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['starts_at' => now()->addDay(), 'ends_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDay()]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['withdrawn_at' => now()->subHour()]);
    }
}
