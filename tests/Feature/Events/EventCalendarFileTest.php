<?php

namespace Tests\Feature\Events;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /eventos/{event}.ics, «Agregar a mi calendario»: an RFC 5545 file of
 * one event the user can see right now; any other one is not found.
 */
class EventCalendarFileTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_a_timed_event_is_in_utc_with_escaped_text(): void
    {
        $event = Event::factory()->create([
            'title' => 'Reunión general; cierre, de mes',
            'description' => "Trae tu portátil.\r\nHabrá café \\ refrigerio",
            'location' => 'Sala 2, piso 3',
            'starts_at' => '2026-11-05 15:00:00',
            'ends_at' => '2026-11-05 17:00:00',
        ]);

        $response = $this->download($event);

        $this->assertSame(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Grupo RYR//Portal RYR//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:event-{$event->id}@localhost",
            'DTSTAMP:20261029T150000Z',
            'SEQUENCE:1793286000',
            'LAST-MODIFIED:20261029T150000Z',
            'DTSTART:20261105T200000Z',
            'DTEND:20261105T220000Z',
            'SUMMARY:Reunión general\; cierre\, de mes',
            'DESCRIPTION:Trae tu portátil.\nHabrá café \\\\ refrigerio',
            'LOCATION:Sala 2\, piso 3',
            'END:VEVENT',
            'END:VCALENDAR',
        ])."\r\n", $response->getContent());
    }

    public function test_an_edited_event_has_a_higher_sequence_and_its_last_change(): void
    {
        $event = Event::factory()->create(['title' => 'Reunión general']);
        $before = $this->lines($this->download($event));

        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:30', 'America/Bogota'));
        $event->update(['location' => 'Sala de juntas']);
        $after = $this->lines($this->download($event->fresh()));

        $this->assertContains('SEQUENCE:1793286000', $before);
        $this->assertContains('SEQUENCE:1793370600', $after);
        $this->assertContains('LAST-MODIFIED:20261030T143000Z', $after);
        // The same UID, so calendar apps update the copy they already have.
        $uid = fn (array $lines) => array_values(array_filter($lines, fn (string $line) => str_starts_with($line, 'UID:')));
        $this->assertSame($uid($before), $uid($after));
    }

    public function test_an_all_day_event_uses_dates_with_the_next_day_as_its_end(): void
    {
        $one = Event::factory()->allDay('2026-11-16')->create(['title' => 'Jornada de salud']);
        $three = Event::factory()->allDay('2026-12-24', '2026-12-26')->create(['title' => 'Vacaciones colectivas']);

        $lines = $this->lines($this->download($one));
        $this->assertContains('DTSTART;VALUE=DATE:20261116', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20261117', $lines);
        $this->assertNotContains('DESCRIPTION:', array_map(fn (string $line) => substr($line, 0, 12), $lines));

        $lines = $this->lines($this->download($three));
        $this->assertContains('DTSTART;VALUE=DATE:20261224', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20261227', $lines);
    }

    public function test_a_yearly_event_repeats_from_its_first_date(): void
    {
        $yearly = Event::factory()->allDay('2020-03-15', '2020-03-16')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        $once = Event::factory()->allDay('2026-11-16')->create(['title' => 'Jornada de salud']);

        // Its first date is long past, and it still downloads.
        $lines = $this->lines($this->download($yearly));
        $this->assertContains('DTSTART;VALUE=DATE:20200315', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20200317', $lines);
        $this->assertContains('RRULE:FREQ=YEARLY', $lines);
        $this->assertLessThan(array_search('SUMMARY:Aniversario', $lines, true), array_search('RRULE:FREQ=YEARLY', $lines, true));

        $this->assertSame([], array_values(array_filter(
            $this->lines($this->download($once)),
            fn (string $line) => str_starts_with($line, 'RRULE'),
        )));
    }

    public function test_a_yearly_event_on_29_february_repeats_on_the_last_day_of_february(): void
    {
        $event = Event::factory()->allDay('2024-02-29')->yearly()->create(['published_at' => '2024-01-01 08:00:00']);

        $lines = $this->lines($this->download($event));

        // RFC 5545 skips 29 February in common years; the portal shows it on the 28th.
        $this->assertContains('DTSTART;VALUE=DATE:20240229', $lines);
        $this->assertContains('RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1', $lines);
    }

    public function test_a_leap_day_event_of_several_days_keeps_its_days_in_the_file(): void
    {
        $event = Event::factory()->allDay('2024-02-29', '2024-03-02')->yearly()->create(['published_at' => '2024-01-01 08:00:00']);

        $lines = $this->lines($this->download($event));

        // Three days from the last day of February, as the portal shows it (28 February to 2 March in 2027).
        $this->assertContains('DTSTART;VALUE=DATE:20240229', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20240303', $lines);
        $this->assertContains('RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1', $lines);
    }

    public function test_a_past_event_that_does_not_repeat_is_still_not_found(): void
    {
        $event = Event::factory()->allDay('2020-03-15')->create(['published_at' => '2020-01-01 08:00:00']);

        $this->actingAs($this->ana)->get(route('events.calendar', $event))->assertNotFound();
    }

    public function test_a_timed_event_without_an_end_has_no_dtend(): void
    {
        $event = Event::factory()->create(['starts_at' => '2026-11-05 15:00:00', 'ends_at' => null]);

        $lines = $this->lines($this->download($event));

        $this->assertContains('DTSTART:20261105T200000Z', $lines);
        $this->assertSame([], array_values(array_filter($lines, fn (string $line) => str_starts_with($line, 'DTEND'))));
    }

    public function test_long_lines_are_folded_at_75_octets_without_cutting_characters(): void
    {
        $description = rtrim(str_repeat('Información útil, ', 20));
        $event = Event::factory()->create(['title' => 'Capacitación', 'description' => $description]);

        $content = $this->download($event)->getContent();

        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $content), 'Only CRLF line endings.');
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $content);

        $physical = explode("\r\n", rtrim($content, "\r\n"));
        foreach ($physical as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Too long: {$line}");
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "A character was cut: {$line}");
        }
        $this->assertGreaterThanOrEqual(5, count(array_filter($physical, fn (string $line) => str_starts_with($line, ' '))));

        $unfolded = explode("\r\n", str_replace("\r\n ", '', rtrim($content, "\r\n")));
        $this->assertContains('DESCRIPTION:'.str_replace(',', '\,', $description), $unfolded);
    }

    public function test_it_is_downloaded_as_a_calendar_file(): void
    {
        $event = Event::factory()->create(['title' => 'Reunión general; cierre, de mes']);

        $this->download($event)
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=reunion-general-cierre-de-mes.ics');
    }

    public function test_events_the_user_cannot_see_are_not_found(): void
    {
        $hidden = [
            Event::factory()->forCompanies(Company::factory()->create())->create(),
            Event::factory()->withdrawn()->create(),
            Event::factory()->scheduled()->create(),
            Event::factory()->past()->create(),
        ];

        foreach ($hidden as $event) {
            $this->actingAs($this->ana)->get(route('events.calendar', $event))->assertNotFound();
        }

        $this->actingAs($this->ana)->get('/eventos/999.ics')->assertNotFound();

        $visible = Event::factory()->create();
        $this->actingAs(User::factory()->emergency()->create())->get(route('events.calendar', $visible))->assertNotFound();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $event = Event::factory()->create();

        $this->get(route('events.calendar', $event))->assertRedirect(route('login'));
    }

    private function download(Event $event): TestResponse
    {
        $this->assertSame(url('/eventos/'.$event->id.'.ics'), route('events.calendar', $event));

        return $this->actingAs($this->ana)->get(route('events.calendar', $event))->assertOk();
    }

    /**
     * @return list<string>
     */
    private function lines(TestResponse $response): array
    {
        return explode("\r\n", str_replace("\r\n ", '', rtrim($response->getContent(), "\r\n")));
    }
}
