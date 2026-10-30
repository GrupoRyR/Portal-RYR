<?php

namespace Tests\Feature\Events;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company dates that repeat every year (odd/tasks/ajustes-inicio-y-tareas.md
 * T3): the next occurrence of an all-day event marked «Se repite cada año»
 * (Event::occurrenceStart()), and that it is never over.
 */
class EventYearlyTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 29 October 2026, 10:00 in Bogotá.
        $this->today('2026-10-29');

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    // --- Occurrence -------------------------------------------------------

    public function test_the_occurrence_is_this_year_until_it_is_over_then_next_year(): void
    {
        $this->assertOccurrence('2026-11-20', '2026-11-20', $this->yearly('2020-11-20'));
        $this->assertOccurrence('2027-03-15', '2027-03-15', $this->yearly('2020-03-15'));
        // Today it is not over yet.
        $this->assertOccurrence('2026-10-29', '2026-10-29', $this->yearly('2019-10-29'));
        $this->assertOccurrence('2027-10-28', '2027-10-28', $this->yearly('2019-10-28'));
    }

    public function test_an_event_of_several_days_under_way_today_keeps_this_years_occurrence(): void
    {
        $event = $this->yearly('2021-10-27', '2021-10-30');

        $this->assertOccurrence('2026-10-27', '2026-10-30', $event);
        $this->assertSame('2026-10-31T00:00:00-05:00', $event->endsAtMoment()->toIso8601String());
    }

    public function test_the_year_wraps(): void
    {
        $this->today('2026-12-20');
        $this->assertOccurrence('2027-01-02', '2027-01-02', $this->yearly('2020-01-02'));

        // Under way across the new year: the occurrence that began last year.
        $this->today('2027-01-01');
        $this->assertOccurrence('2026-12-30', '2027-01-03', $this->yearly('2020-12-30', '2021-01-03'));
    }

    public function test_29_february_falls_on_28_february_in_common_years(): void
    {
        $event = $this->yearly('2024-02-29');

        $this->assertOccurrence('2027-02-28', '2027-02-28', $event);

        $this->today('2027-10-29');
        $this->assertOccurrence('2028-02-29', '2028-02-29', $event);
    }

    public function test_a_first_date_still_ahead_is_the_occurrence(): void
    {
        $this->assertOccurrence('2027-06-01', '2027-06-01', $this->yearly('2027-06-01'));
        $this->assertOccurrence('2028-06-01', '2028-06-02', $this->yearly('2028-06-01', '2028-06-02'));
    }

    public function test_only_an_all_day_event_repeats(): void
    {
        $timed = Event::factory()->yearly()->make(['starts_at' => '2020-11-05 15:00:00', 'ends_at' => '2020-11-05 17:00:00']);

        $this->assertFalse($timed->repeatsYearly());
        $this->assertSame('2020-11-05 15:00:00', $timed->occurrenceStart()->format('Y-m-d H:i:s'));
        $this->assertSame(Event::STATUS_PAST, $timed->status);

        $once = Event::factory()->allDay('2020-11-20')->make();
        $this->assertFalse($once->repeatsYearly());
        $this->assertSame('2020-11-20', $once->occurrenceStart()->toDateString());
    }

    // --- Visibility -------------------------------------------------------

    public function test_a_yearly_event_is_never_over(): void
    {
        $since2020 = Event::factory()->allDay('2020-03-15')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->allDay('2020-03-15')->create(['title' => 'Una sola vez']);

        $this->assertSame(['Aniversario'], $this->visibleTitles());
        $this->assertSame(Event::STATUS_PUBLISHED, $since2020->fresh()->status);
        $this->assertSame([$since2020->id], Event::query()->inStatus(Event::STATUS_PUBLISHED)->pluck('id')->all());
        $this->assertSame([], Event::query()->inStatus(Event::STATUS_PAST)->whereKey($since2020->id)->pluck('id')->all());
    }

    public function test_the_other_visibility_rules_still_hide_a_yearly_event(): void
    {
        Event::factory()->allDay('2020-03-15')->yearly()->forCompanies($this->segurex)->create(['title' => 'Cierre contable']);
        Event::factory()->allDay('2020-03-15')->yearly()->withdrawn()->create(['title' => 'Retirado']);
        Event::factory()->allDay('2020-03-15')->yearly()->scheduled()->create(['title' => 'Programado']);
        Event::factory()->allDay('2020-03-15')->yearly()->forCompanies(Company::factory()->create())->create(['title' => 'Otra empresa']);
        Event::factory()->yearly()->past()->create(['title' => 'Con hora']);

        $this->assertSame(['Cierre contable'], $this->visibleTitles());
        $this->assertSame([], Event::query()->visibleTo(User::factory()->emergency()->create())->pluck('title')->all());
    }

    private function yearly(string $firstDay, ?string $lastDay = null): Event
    {
        return Event::factory()->allDay($firstDay, $lastDay)->yearly()->make();
    }

    private function assertOccurrence(string $firstDay, string $lastDay, Event $event): void
    {
        $this->assertTrue($event->repeatsYearly());
        $this->assertSame($firstDay.' 00:00:00', $event->occurrenceStart()->format('Y-m-d H:i:s'), 'First day.');
        $this->assertSame($lastDay, $event->occurrenceLastDay()->toDateString(), 'Last day.');
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);
    }

    private function today(string $day): void
    {
        $this->travelTo(CarbonImmutable::parse($day.' 10:00', 'America/Bogota'));
    }

    /**
     * @return list<string>
     */
    private function visibleTitles(): array
    {
        return Event::query()->visibleTo($this->ana)->pluck('title')->all();
    }
}
