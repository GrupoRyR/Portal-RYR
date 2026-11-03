<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published, timed event next week for every company unless a state says
 * otherwise.
 *
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addWeek()->setTime(15, 0);

        return [
            'title' => fake()->sentence(4),
            'description' => null,
            'location' => null,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'all_day' => false,
            'audience' => Event::AUDIENCE_ALL,
            'published_at' => now()->subDay(),
        ];
    }

    /**
     * Only for the given companies.
     */
    public function forCompanies(Company ...$companies): static
    {
        return $this
            ->state(fn () => ['audience' => Event::AUDIENCE_COMPANIES])
            ->afterCreating(fn (Event $event) => $event->companies()->attach(collect($companies)->pluck('id')));
    }

    /**
     * All day on the given date (Y-m-d), through $lastDay when given.
     */
    public function allDay(string $day, ?string $lastDay = null): static
    {
        return $this->state(fn () => [
            'all_day' => true,
            'starts_at' => $day.' 00:00:00',
            'ends_at' => $lastDay === null ? null : $lastDay.' 00:00:00',
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['published_at' => now()->addDay()]);
    }

    public function past(): static
    {
        return $this->state(fn () => ['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['withdrawn_at' => now()->subHour()]);
    }
}
