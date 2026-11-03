<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `proximas_fechas`: the Colombian holidays and the events each account of
 * the connection sees, within the next `dias` days, soonest first, as
 * «Próximas fechas» of the home screen lists them.
 */
class ProximasFechasTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function dates(array $arguments = []): array
    {
        return $this->toolResult('proximas_fechas', $arguments)['fechas'];
    }

    public function test_holidays_and_both_accounts_events_come_soonest_first_and_labelled(): void
    {
        $committee = Event::factory()->forCompanies($this->segurex)->create([
            'title' => 'Comité de seguridad',
            'location' => 'Sala 2',
            'starts_at' => '2026-11-10 15:00:00',
            'ends_at' => '2026-11-10 17:00:00',
        ]);
        $forAll = Event::factory()->create([
            'title' => 'Simulacro',
            'description' => 'Evacuación de todas las sedes.',
            'starts_at' => '2026-11-12 10:00:00',
            'ends_at' => null,
        ]);
        $fair = Event::factory()->forCompanies($this->derivados)->allDay('2026-11-20')->create(['title' => 'Feria Derivados']);
        $anniversary = Event::factory()->forCompanies($this->segurex)->allDay('2020-11-25')->yearly()->create(['title' => 'Aniversario Segurex']);

        $this->assertSame([
            [
                'tipo' => 'Evento',
                'titulo' => 'Comité de seguridad',
                'fecha' => '2026-11-10T15:00:00-05:00',
                'fecha_texto' => 'Martes 10 de noviembre · 15:00 a 17:00',
                'lugar' => 'Sala 2',
                'descripcion' => null,
                'empresas' => ['Segurex'],
                'url' => route('events.index').'#e-'.$committee->id,
            ],
            [
                'tipo' => 'Evento',
                'titulo' => 'Simulacro',
                'fecha' => '2026-11-12T10:00:00-05:00',
                'fecha_texto' => 'Jueves 12 de noviembre · 10:00',
                'lugar' => null,
                'descripcion' => 'Evacuación de todas las sedes.',
                'empresas' => ['Segurex', 'Derivados'],
                'url' => route('events.index').'#e-'.$forAll->id,
            ],
            [
                'tipo' => 'Puente festivo',
                'titulo' => 'Independencia de Cartagena',
                'fecha' => '2026-11-16T00:00:00-05:00',
                'fecha_texto' => 'Lunes 16 de noviembre · Todo el día',
                'lugar' => null,
                'descripcion' => null,
                'empresas' => ['Segurex', 'Derivados'],
                'url' => null,
            ],
            [
                'tipo' => 'Evento',
                'titulo' => 'Feria Derivados',
                'fecha' => '2026-11-20T00:00:00-05:00',
                'fecha_texto' => 'Viernes 20 de noviembre · Todo el día',
                'lugar' => null,
                'descripcion' => null,
                'empresas' => ['Derivados'],
                'url' => route('events.index').'#e-'.$fair->id,
            ],
            [
                'tipo' => 'Cada año',
                'titulo' => 'Aniversario Segurex',
                'fecha' => '2026-11-25T00:00:00-05:00',
                'fecha_texto' => 'Miércoles 25 de noviembre · Todo el día',
                'lugar' => null,
                'descripcion' => null,
                'empresas' => ['Segurex'],
                'url' => route('events.index').'#e-'.$anniversary->id,
            ],
        ], $this->dates());
    }

    public function test_the_window_is_thirty_days_by_default_and_up_to_ninety(): void
    {
        Event::factory()->allDay('2026-12-20')->create(['title' => 'Novena']);

        $this->assertSame(['Independencia de Cartagena'], array_column($this->dates(), 'titulo'));
        $this->assertSame([], $this->dates(['dias' => 1]));

        $ninety = $this->dates(['dias' => 90]);
        $this->assertSame(
            ['Independencia de Cartagena', 'Inmaculada Concepción', 'Novena', 'Navidad', 'Año Nuevo', 'Reyes Magos'],
            array_column($ninety, 'titulo'),
        );
        // Monday or Friday makes a «puente»; Tuesday 8 December does not.
        $this->assertSame(['Puente festivo', 'Festivo', 'Evento', 'Puente festivo', 'Puente festivo', 'Puente festivo'], array_column($ninety, 'tipo'));
        // Another year is spelled out.
        $this->assertSame('Viernes 1 de enero de 2027 · Todo el día', $ninety[4]['fecha_texto']);
    }

    public function test_events_of_a_company_outside_the_connection_never_appear(): void
    {
        Event::factory()->forCompanies($this->other)->create(['title' => 'Solo Otra', 'starts_at' => '2026-11-05 10:00:00', 'ends_at' => null]);
        Event::factory()->forCompanies($this->segurex, $this->other)->create(['title' => 'Segurex y Otra', 'starts_at' => '2026-11-06 10:00:00', 'ends_at' => null]);

        $dates = $this->dates();

        $this->assertSame(['Segurex y Otra', 'Independencia de Cartagena'], array_column($dates, 'titulo'));
        $this->assertSame(['Segurex'], $dates[0]['empresas']);
        $this->assertNotContains('Otra', array_merge(...array_column($dates, 'empresas')));
    }

    public function test_withdrawn_unpublished_and_past_events_are_left_out(): void
    {
        Event::factory()->create(['title' => 'Retirado', 'starts_at' => '2026-11-05 10:00:00', 'ends_at' => null, 'withdrawn_at' => now()]);
        Event::factory()->create(['title' => 'Sin publicar', 'starts_at' => '2026-11-05 10:00:00', 'ends_at' => null, 'published_at' => now()->addDay()]);
        Event::factory()->create(['title' => 'Pasado', 'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00']);

        $this->assertSame(['Independencia de Cartagena'], array_column($this->dates(), 'titulo'));
    }

    public function test_after_unlinking_the_other_company_s_events_no_longer_appear(): void
    {
        Event::factory()->forCompanies($this->derivados)->allDay('2026-11-20')->create(['title' => 'Feria Derivados']);
        $this->assertContains('Feria Derivados', array_column($this->dates(), 'titulo'));

        $this->unlinkTheLinkedAccount();

        $dates = $this->dates();
        $this->assertSame(['Independencia de Cartagena'], array_column($dates, 'titulo'));
        $this->assertSame(['Segurex'], $dates[0]['empresas']);
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        Event::factory()->forCompanies($this->derivados)->allDay('2026-11-20')->create(['title' => 'Feria Derivados']);

        $this->makeLinkedAccountUnusable($case);

        $this->assertNotContains('Feria Derivados', array_column($this->dates(), 'titulo'));
    }

    public function test_at_most_thirty_dates(): void
    {
        foreach (range(0, 39) as $i) {
            Event::factory()->forCompanies($i % 2 === 0 ? $this->segurex : $this->derivados)->create([
                'starts_at' => now()->addDays(1 + intdiv($i, 3))->setTime(8 + $i % 3, 0),
                'ends_at' => null,
            ]);
        }

        $result = $this->toolResult('proximas_fechas', ['dias' => 90]);

        $this->assertSame(30, $result['limite']);
        $this->assertCount(30, $result['fechas']);
        $starts = array_column($result['fechas'], 'fecha');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badDays(): array
    {
        return ['zero' => [0], 'too many' => [91], 'text' => ['treinta']];
    }

    #[DataProvider('badDays')]
    public function test_days_out_of_range_are_refused(mixed $days): void
    {
        $this->assertStringContainsString('dias', $this->toolError('proximas_fechas', ['dias' => $days]));
    }
}
