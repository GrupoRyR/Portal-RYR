<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Search\HomeSearchIndex;
use App\View\Home\EventDate;
use App\View\Home\HolidayDay;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Company events on the home screen (odd/tasks/inicio-fase-2.md WU2):
 * «Próximas fechas» of «Mi día» merges them with the holidays, and the
 * «Ctrl K» search lists them among the dates. One query at most.
 */
class HomeEventsTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 29 October 2026, 10:00 in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_upcoming_dates_merge_events_and_holidays_in_order(): void
    {
        Event::factory()->create(['title' => 'Reunión general', 'starts_at' => '2026-11-05 15:00:00', 'ends_at' => '2026-11-05 17:00:00']);
        Event::factory()->allDay('2026-11-16')->create(['title' => 'Jornada de salud']);
        Event::factory()->forCompanies($this->segurex)->create(['title' => 'Pausa activa', 'starts_at' => '2026-10-29 16:00:00', 'ends_at' => null]);

        $page = $this->build();

        // Five at most: the holiday of 8 December is left out.
        $this->assertSame(
            ['Pausa activa', 'Todos los Santos', 'Reunión general', 'Independencia de Cartagena', 'Jornada de salud'],
            $this->titles($page),
        );
        $this->assertCount(HomePageBuilder::UPCOMING_DATE_LIMIT, $page->upcomingDates);
        // Holidays alone are unchanged (the date line still names the next one).
        $this->assertSame('2026-11-02', $page->nextHoliday?->isoDate());
        $this->assertTrue($page->hasEvents);
    }

    public function test_events_past_the_horizon_or_not_visible_are_left_out(): void
    {
        Event::factory()->create(['title' => 'Muy lejos', 'starts_at' => '2027-01-20 15:00:00', 'ends_at' => null]);
        Event::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Otra empresa']);
        Event::factory()->withdrawn()->create(['title' => 'Retirado']);
        Event::factory()->scheduled()->create(['title' => 'Programado']);
        Event::factory()->past()->create(['title' => 'Pasado']);

        $page = $this->build();

        $this->assertSame(['Todos los Santos', 'Independencia de Cartagena', 'Inmaculada Concepción', 'Navidad'], $this->titles($page));
        // «Muy lejos» is not in the list, but «Ver eventos» still leads to it.
        $this->assertTrue($page->hasEvents);
    }

    public function test_the_events_link_shows_for_any_visible_upcoming_event(): void
    {
        Event::factory()->create(['title' => 'En un año y medio', 'starts_at' => '2028-04-01 09:00:00', 'ends_at' => null]);
        Event::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Otra empresa']);

        $xpath = $this->xpath($this->home());

        $this->assertSame(1, $xpath->query('//section[@id="fechas"]//a[@href="'.route('events.index').'"]')->length);
        $this->assertNotContains('En un año y medio', array_column($this->index()['dates'], 'title'));
    }

    public function test_an_event_under_way_says_until_when(): void
    {
        $week = Event::factory()->allDay('2026-10-30', '2026-11-03')->create(['title' => 'Semana de seguridad']);
        Event::factory()->create(['title' => 'Auditoría externa', 'starts_at' => '2026-10-28 08:00:00', 'ends_at' => '2026-10-30 18:00:00', 'published_at' => '2026-10-01 00:00:00']);

        // Before it starts it reads as usual.
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'America/Bogota'));
        $before = $this->eventRows()['Auditoría externa'] ?? '';
        $this->assertStringContainsString('Miércoles · 08:00 · Evento', $before);
        $this->assertStringNotContainsString('En curso', $before);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'America/Bogota'));
        $xpath = $this->xpath($this->home());
        $row = $xpath->query('//section[@id="fechas"]//li[.//a[@href="'.route('events.index').'#e-'.$week->id.'"]]')->item(0);
        $this->assertStringContainsString('En curso · hasta el 3 de noviembre · Evento', $this->squish($row->textContent));
        $this->assertSame('2026-10-31', $xpath->query('.//time', $row)->item(0)->getAttribute('datetime'));
        $this->assertSame(0, $xpath->query('.//*[@data-event-weekday]', $row)->length);

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->assertStringContainsString('En curso · hasta el 30 de octubre, 18:00 · Evento', $this->eventRows()['Auditoría externa']);
    }

    public function test_event_rows_are_labelled_and_marked_for_the_saved_copy(): void
    {
        $today = Event::factory()->create(['title' => 'Pausa activa', 'starts_at' => '2026-10-29 16:00:00', 'ends_at' => '2026-10-29 16:30:00']);
        $allDay = Event::factory()->allDay('2026-11-03', '2026-11-04')->create(['title' => 'Feria de proveedores']);

        $response = $this->home();
        $xpath = $this->xpath($response);

        $rows = $xpath->query('//section[@id="fechas"]//li');
        $this->assertSame(['event', 'holiday', 'event'], array_map(
            fn ($row) => $row->hasAttribute('data-event-end') ? 'event' : ($row->hasAttribute('data-holiday-date') ? 'holiday' : '?'),
            array_slice(iterator_to_array($rows), 0, 3),
        ));

        $first = $rows->item(0);
        $this->assertSame('2026-10-29', $first->getAttribute('data-event-date'));
        $this->assertSame('2026-10-29T16:30:00-05:00', $first->getAttribute('data-event-end'));
        $this->assertStringContainsString('Hoy · 16:00 · Evento', $this->squish($first->textContent));
        $this->assertSame(1, $xpath->query('.//*[@data-event-today]', $first)->length);
        $this->assertSame('Jueves', $xpath->query('.//*[@data-event-weekday]', $first)->item(0)->getAttribute('data-event-weekday'));
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('events.index').'#e-'.$today->id.'"]', $first)->length);

        $holiday = $rows->item(1);
        $this->assertStringContainsString('Lunes · Puente festivo', $this->squish($holiday->textContent));

        $third = $rows->item(2);
        $this->assertSame('2026-11-05T00:00:00-05:00', $third->getAttribute('data-event-end'));
        $this->assertStringContainsString('Martes · Hasta el 4 de noviembre · Evento', $this->squish($third->textContent));
        $this->assertSame(0, $xpath->query('.//*[@data-event-today]', $third)->length);
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('events.index').'#e-'.$allDay->id.'"]', $third)->length);

        // «Ver eventos» only when there are events to see.
        $this->assertSame(1, $xpath->query('//section[@id="fechas"]//a[@href="'.route('events.index').'"]')->length);
    }

    public function test_there_is_no_events_link_without_events(): void
    {
        $xpath = $this->xpath($this->home());

        $this->assertSame(0, $xpath->query('//section[@id="fechas"]//a[@href="'.route('events.index').'"]')->length);
    }

    public function test_the_search_lists_visible_events_among_the_dates(): void
    {
        $event = Event::factory()->create(['title' => 'Reunión general', 'starts_at' => '2026-11-05 15:00:00', 'ends_at' => null]);
        $allDay = Event::factory()->allDay('2026-12-24', '2026-12-26')->create(['title' => 'Vacaciones colectivas']);
        Event::factory()->create(['title' => 'En un año y medio', 'starts_at' => '2028-04-01 09:00:00', 'ends_at' => null]);
        Event::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Otra empresa']);
        Event::factory()->withdrawn()->create(['title' => 'Retirado']);
        Event::factory()->scheduled()->create(['title' => 'Programado']);
        Event::factory()->past()->create(['title' => 'Pasado']);

        $dates = $this->index()['dates'];
        $titles = array_column($dates, 'title');

        $this->assertSame(['Todos los Santos', 'Reunión general'], array_slice($titles, 0, 2));
        $this->assertContains('Vacaciones colectivas', $titles);
        foreach (['En un año y medio', 'Otra empresa', 'Retirado', 'Programado', 'Pasado'] as $hidden) {
            $this->assertNotContains($hidden, $titles);
        }

        $entry = $dates[1];
        $this->assertSame('Evento · jueves 5 de noviembre, 15:00', $entry['detail']);
        $this->assertSame(route('events.index').'#e-'.$event->id, $entry['href']);
        $this->assertSame('2026-11-05', $entry['date']);
        // A saved copy hides it at this moment, like the home list (data-event-end).
        $this->assertSame('2026-11-06T00:00:00-05:00', $entry['end']);
        $this->assertSame('reunion general evento jueves 5 de noviembre, 15:00', $entry['search']);

        $vacation = $dates[array_search('Vacaciones colectivas', $titles, true)];
        // Hidden in a saved copy from the day after its last day.
        $this->assertSame('2026-12-26', $vacation['date']);
        $this->assertSame(route('events.index').'#e-'.$allDay->id, $vacation['href']);
        // Navidad (25 December) comes after it: it starts on the 24th.
        $this->assertLessThan(array_search('Navidad', $titles, true), array_search('Vacaciones colectivas', $titles, true));
    }

    public function test_a_yearly_event_shows_on_its_next_occurrence_within_the_window(): void
    {
        $anniversary = Event::factory()->allDay('2020-11-03')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        // Next on 20 January 2027: past the 60 days of the list, within the search's twelve months.
        Event::factory()->allDay('2020-01-20')->yearly()->create(['title' => 'Cierre contable', 'published_at' => '2020-01-01 08:00:00']);

        $titles = $this->titles($this->build());
        $this->assertSame(['Todos los Santos', 'Aniversario', 'Independencia de Cartagena'], array_slice($titles, 0, 3));
        $this->assertNotContains('Cierre contable', $titles);

        $xpath = $this->xpath($this->home());
        $row = $xpath->query('//section[@id="fechas"]//li[@data-event-end]')->item(0);
        $this->assertSame('2026-11-03', $row->getAttribute('data-event-date'));
        $this->assertSame('2026-11-04T00:00:00-05:00', $row->getAttribute('data-event-end'));
        $this->assertSame('2026-11-03', $xpath->query('.//time', $row)->item(0)->getAttribute('datetime'));
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('events.index').'#e-'.$anniversary->id.'"]', $row)->length);

        $dates = $this->index()['dates'];
        $closing = $dates[array_search('Cierre contable', array_column($dates, 'title'), true)];
        $this->assertSame('2027-01-20', $closing['date']);
        $this->assertSame('2027-01-21T00:00:00-05:00', $closing['end']);
        $this->assertStringContainsString('miércoles 20 de enero de 2027', $closing['detail']);
    }

    public function test_a_yearly_event_reads_every_year_instead_of_event(): void
    {
        Event::factory()->allDay('2020-11-03')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->allDay('2026-11-05')->create(['title' => 'Jornada de salud']);

        $rows = $this->eventRows();
        $this->assertStringContainsString('Martes · Cada año', $rows['Aniversario']);
        $this->assertStringNotContainsString('Evento', $rows['Aniversario']);
        $this->assertStringContainsString('3 de noviembre, cada año:', $rows['Aniversario']);
        $this->assertStringContainsString('Jueves · Evento', $rows['Jornada de salud']);

        $dates = $this->index()['dates'];
        $entry = $dates[array_search('Aniversario', array_column($dates, 'title'), true)];
        $this->assertSame('Cada año · martes 3 de noviembre', $entry['detail']);
        $this->assertSame('aniversario cada ano martes 3 de noviembre', $entry['search']);
    }

    public function test_old_yearly_events_do_not_crowd_out_the_soonest_ones(): void
    {
        // Their stored first dates come before everything else; their next
        // occurrence (15 March 2027) is past the 60 days of the list.
        Event::factory()->count(HomeSearchIndex::EVENT_LIMIT + 5)->allDay('2019-03-15')->yearly()->create(['published_at' => '2019-01-01 08:00:00']);
        Event::factory()->allDay('2020-11-05')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->create(['title' => 'Pausa activa', 'starts_at' => '2026-10-30 09:00:00', 'ends_at' => null]);

        $titles = $this->titles($this->build());
        $this->assertContains('Pausa activa', $titles);
        $this->assertContains('Aniversario', $titles);

        $search = array_column($this->index()['dates'], 'title');
        $this->assertContains('Pausa activa', $search);
        $this->assertContains('Aniversario', $search);
    }

    public function test_events_cost_one_query_whatever_their_number(): void
    {
        $this->openableApp();
        $without = $this->countQueries();

        Event::factory()->create();
        $one = $this->countQueries();

        Event::factory()->count(8)->forCompanies($this->segurex)->create();
        $many = $this->countQueries();

        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual($without + 1, $one);
        $this->assertLessThanOrEqual(12, $many);
    }

    public function test_the_page_with_events_ships_no_livewire_or_alpine(): void
    {
        $this->openableApp();
        Event::factory()->create();

        $this->home()
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_viewers_without_a_company_get_no_events(): void
    {
        Event::factory()->create(['title' => 'Para todas']);

        $page = app(HomePageBuilder::class)->build(User::factory()->emergency()->create());

        $this->assertNotContains('Para todas', $this->titles($page));
    }

    /**
     * Text of each event row of «Próximas fechas», by title.
     *
     * @return array<string, string>
     */
    private function eventRows(): array
    {
        $xpath = $this->xpath($this->home());
        $rows = [];
        foreach ($xpath->query('//section[@id="fechas"]//li[@data-event-end]') as $row) {
            $rows[$this->squish($xpath->query('.//a', $row)->item(0)->textContent)] = $this->squish($row->textContent);
        }

        return $rows;
    }

    private function build(): HomePage
    {
        return app(HomePageBuilder::class)->build($this->ana->fresh());
    }

    /**
     * @return list<string>
     */
    private function titles(HomePage $page): array
    {
        return array_map(fn (HolidayDay|EventDate $date) => $date instanceof EventDate ? $date->title : $date->label(), $page->upcomingDates);
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->ana->fresh())->get('/')->assertOk();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function index(): array
    {
        $found = preg_match('~<script type="application/json" id="search-index">(.*?)</script>~s', $this->home()->getContent(), $match);
        $this->assertSame(1, $found, 'Expected the embedded search index.');

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function countQueries(): int
    {
        $user = $this->ana->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        DB::flushQueryLog();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }

    private function openableApp(): App
    {
        $app = App::factory()->forCompanies($this->segurex)->create();
        AppAccessRule::forCompany($app, $this->segurex);

        return $app;
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new DOMXPath($document);
    }

    private function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
