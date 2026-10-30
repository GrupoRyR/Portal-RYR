<?php

namespace Tests\Feature\Events;

use App\Http\Controllers\EventController;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /eventos: the upcoming events the user can see, soonest first, each with
 * an anchor (#e-{id}) for «Próximas fechas» and the search, and «Agregar a
 * mi calendario». Plain HTML, no Livewire.
 */
class EventPageTest extends TestCase
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

    public function test_it_lists_visible_upcoming_events_soonest_first(): void
    {
        $later = Event::factory()->allDay('2026-11-16')->create(['title' => 'Jornada de salud']);
        $sooner = Event::factory()->forCompanies($this->segurex)->create([
            'title' => 'Reunión general',
            'location' => 'Sala 2',
            'description' => "Orden del día:\n<b>cierre</b>",
            'starts_at' => '2026-11-05 15:00:00',
            'ends_at' => '2026-11-05 17:00:00',
        ]);
        Event::factory()->forCompanies(Company::factory()->create())->create(['title' => 'De otra empresa']);
        Event::factory()->withdrawn()->create(['title' => 'Retirado']);
        Event::factory()->past()->create(['title' => 'Ya pasó']);
        Event::factory()->scheduled()->create(['title' => 'Aún no publicado']);

        $response = $this->page()
            ->assertSeeInOrder(['Reunión general', 'Jornada de salud'])
            ->assertSeeText('Jueves 5 de noviembre · 15:00 a 17:00')
            ->assertSeeText('Lunes 16 de noviembre · Todo el día')
            ->assertSeeText('Sala 2')
            ->assertSee('&lt;b&gt;cierre&lt;/b&gt;', false)
            ->assertDontSeeText('De otra empresa')
            ->assertDontSeeText('Retirado')
            ->assertDontSeeText('Ya pasó')
            ->assertDontSeeText('Aún no publicado');

        $xpath = $this->xpath($response);
        foreach ([$sooner, $later] as $event) {
            $this->assertSame(1, $xpath->query('//*[@id="e-'.$event->id.'"]')->length);
            $link = $xpath->query('//*[@id="e-'.$event->id.'"]//a[@href="'.route('events.calendar', $event).'"]');
            $this->assertSame(1, $link->length);
            $this->assertStringContainsString('Agregar a mi calendario', $link->item(0)->textContent);
            $this->assertTrue($link->item(0)->hasAttribute('download'));
        }
    }

    public function test_multi_day_and_open_ended_events_read_well(): void
    {
        Event::factory()->allDay('2026-12-24', '2026-12-26')->create(['title' => 'Vacaciones']);
        Event::factory()->create(['title' => 'Simulacro', 'starts_at' => '2026-11-05 09:30:00', 'ends_at' => null]);
        Event::factory()->create(['title' => 'Viaje', 'starts_at' => '2027-01-11 08:00:00', 'ends_at' => '2027-01-12 18:00:00']);

        $this->page()
            ->assertSeeText('Del jueves 24 de diciembre al sábado 26 de diciembre')
            ->assertSeeText('Jueves 5 de noviembre · 09:30')
            ->assertSeeText('Del lunes 11 de enero de 2027, 08:00 al martes 12 de enero de 2027, 18:00');
    }

    public function test_an_event_under_way_says_until_when_instead_of_its_start(): void
    {
        $week = Event::factory()->allDay('2026-10-30', '2026-11-03')->create(['title' => 'Semana de seguridad']);
        Event::factory()->create(['title' => 'Auditoría externa', 'starts_at' => '2026-10-28 08:00:00', 'ends_at' => '2026-10-30 18:00:00']);

        // The day before it starts it reads as usual.
        $this->page()->assertSeeText('Del viernes 30 de octubre al martes 3 de noviembre');

        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00', 'America/Bogota'));
        $response = $this->page()
            ->assertSeeText('En curso · hasta el martes 3 de noviembre')
            ->assertDontSeeText('Del viernes 30 de octubre');

        // The date beside it is today, not the start that already passed.
        $badge = $this->xpath($response)->query('//*[@id="e-'.$week->id.'"]//time')->item(0);
        $this->assertSame('2026-10-31', $badge->getAttribute('datetime'));
        $this->assertSame('31 oct', trim((string) preg_replace('/\s+/', ' ', $badge->textContent)));

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));
        $this->page()->assertSeeText('En curso · hasta el viernes 30 de octubre, 18:00');
    }

    public function test_a_yearly_event_is_listed_on_its_next_occurrence(): void
    {
        $anniversary = Event::factory()->allDay('2020-11-20')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->allDay('2020-03-15')->yearly()->create(['title' => 'Cierre contable', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->allDay('2026-12-01')->create(['title' => 'Jornada de salud']);

        $response = $this->page()
            ->assertSeeInOrder(['Aniversario', 'Jornada de salud', 'Cierre contable'])
            ->assertSeeText('Viernes 20 de noviembre · Todo el día')
            ->assertSeeText('Lunes 15 de marzo de 2027 · Todo el día')
            ->assertDontSeeText('2020');

        $badge = $this->xpath($response)->query('//*[@id="e-'.$anniversary->id.'"]//time')->item(0);
        $this->assertSame('2026-11-20', $badge->getAttribute('datetime'));
    }

    public function test_a_cut_list_says_so(): void
    {
        Event::factory()->count(EventController::LIMIT)->create();
        $this->page()->assertDontSeeText('Se muestran los');

        Event::factory()->create();
        $response = $this->page()->assertSeeText('Se muestran los '.EventController::LIMIT.' eventos más próximos.');
        $this->assertSame(EventController::LIMIT, $this->xpath($response)->query('//ol/li')->length);
    }

    public function test_without_events_it_says_so(): void
    {
        $this->page()->assertSeeText('No hay eventos próximos.');
    }

    public function test_it_ships_no_livewire_or_alpine(): void
    {
        Event::factory()->create();

        $this->page()
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('events.index'))->assertRedirect(route('login'));
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->ana)->get(route('events.index'))->assertOk();
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }
}
