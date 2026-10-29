<?php

namespace Tests\Feature\Events;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Who sees an event and until when (odd/tasks/inicio-fase-2.md WU2): the
 * audience rules of announcements, from `published_at` until it is over.
 */
class EventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 29 October 2026, 10:00 in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_only_published_events_for_the_users_company_are_visible(): void
    {
        Event::factory()->create(['title' => 'Para todas']);
        Event::factory()->forCompanies($this->segurex)->create(['title' => 'Solo Segurex']);
        Event::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Otra empresa']);
        Event::factory()->withdrawn()->create(['title' => 'Retirado']);
        Event::factory()->scheduled()->create(['title' => 'Programado']);
        Event::factory()->past()->create(['title' => 'Pasado']);

        $this->assertEqualsCanonicalizing(['Para todas', 'Solo Segurex'], $this->visibleTitles($this->ana));
    }

    public function test_users_without_a_company_see_no_events(): void
    {
        Event::factory()->create();

        $this->assertSame([], $this->visibleTitles(User::factory()->emergency()->create()));
        $this->assertSame([], $this->visibleTitles(User::factory()->create(['company_id' => null])));
    }

    public function test_an_event_stays_until_it_is_over(): void
    {
        Event::factory()->create(['title' => 'Terminó a las 9', 'starts_at' => '2026-10-29 08:00:00', 'ends_at' => '2026-10-29 09:00:00']);
        Event::factory()->create(['title' => 'En curso', 'starts_at' => '2026-10-29 09:00:00', 'ends_at' => '2026-10-29 11:00:00']);
        Event::factory()->create(['title' => 'Sin fin, hoy', 'starts_at' => '2026-10-29 08:00:00', 'ends_at' => null]);
        Event::factory()->create(['title' => 'Sin fin, ayer', 'starts_at' => '2026-10-28 20:00:00', 'ends_at' => null]);
        Event::factory()->allDay('2026-10-29')->create(['title' => 'Todo el día, hoy']);
        Event::factory()->allDay('2026-10-28')->create(['title' => 'Todo el día, ayer']);
        Event::factory()->allDay('2026-10-27', '2026-10-30')->create(['title' => 'Varios días']);
        Event::factory()->allDay('2026-10-26', '2026-10-28')->create(['title' => 'Varios días, ya pasó']);

        $this->assertEqualsCanonicalizing(
            ['En curso', 'Sin fin, hoy', 'Todo el día, hoy', 'Varios días'],
            $this->visibleTitles($this->ana),
        );
    }

    public function test_the_status_attribute_matches_the_status_query(): void
    {
        $events = [
            Event::STATUS_PUBLISHED => Event::factory()->create(),
            Event::STATUS_SCHEDULED => Event::factory()->scheduled()->create(),
            Event::STATUS_PAST => Event::factory()->past()->create(),
            Event::STATUS_WITHDRAWN => Event::factory()->withdrawn()->create(),
        ];

        foreach ($events as $status => $event) {
            $this->assertSame($status, $event->fresh()->status);
            $this->assertSame([$event->id], Event::query()->inStatus($status)->pluck('id')->all(), "Query for «{$status}».");
        }
    }

    public function test_the_end_moment_of_each_kind(): void
    {
        $timed = Event::factory()->make(['starts_at' => '2026-11-05 15:00:00', 'ends_at' => '2026-11-05 17:00:00']);
        $open = Event::factory()->make(['starts_at' => '2026-11-05 15:00:00', 'ends_at' => null]);
        $allDay = Event::factory()->allDay('2026-11-16', '2026-11-17')->make();

        $this->assertSame('2026-11-05T17:00:00-05:00', $timed->endsAtMoment()->toIso8601String());
        $this->assertSame('2026-11-06T00:00:00-05:00', $open->endsAtMoment()->toIso8601String());
        $this->assertSame('2026-11-18T00:00:00-05:00', $allDay->endsAtMoment()->toIso8601String());
    }

    public function test_changes_withdrawing_and_companies_are_recorded(): void
    {
        $event = (new Event)->saveWithCompanies([
            'title' => 'Simulacro',
            'audience' => Event::AUDIENCE_COMPANIES,
            'starts_at' => '2026-11-05 15:00:00',
            'published_at' => now(),
        ], [$this->segurex->id]);
        $event->withdraw();
        $event->republish();

        $this->assertSame(
            ['created', 'event.companies_changed', 'event.withdrawn', 'event.republished'],
            Activity::query()->where('log_name', Event::AUDIT_LOG)->orderBy('id')->pluck('event')->all(),
        );
        $changed = Activity::query()->where('event', 'event.companies_changed')->sole();
        $this->assertSame(['Segurex'], $changed->properties['attributes']['companies'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function visibleTitles(User $user): array
    {
        return Event::query()->visibleTo($user)->pluck('title')->all();
    }
}
