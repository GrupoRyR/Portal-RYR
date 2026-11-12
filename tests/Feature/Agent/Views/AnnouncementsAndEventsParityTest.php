<?php

namespace Tests\Feature\Agent\Views;

use App\Models\Announcement;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Views\Concerns\BuildsAgentWorld;
use Tests\TestCase;

/**
 * v_anuncios_vigentes and v_eventos_proximos against the Eloquent scopes the
 * portal itself uses (`visibleTo`, `Event::visibleSoonest`,
 * `Event::occurrenceStart`) on the same rows, at the same frozen instant.
 */
class AnnouncementsAndEventsParityTest extends TestCase
{
    use BuildsAgentWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCompanies();
    }

    /** @return array<string, array{string, string}> */
    public static function companies(): array
    {
        return ['segurex' => ['sgx', 'SGX'], 'derivados' => ['sdc', 'SDC']];
    }

    #[DataProvider('companies')]
    public function test_announcements_match_visible_to(string $property, string $code): void
    {
        $this->seedAnnouncements();
        $this->freeze('2026-11-12 10:00:00');
        $company = $this->{$property};

        $expected = Announcement::query()->visibleTo($this->userOf($company))->get();
        $rows = $this->rows('v_anuncios_vigentes', $code);

        $this->assertNotEmpty($expected);
        $this->assertSame($this->sortedIds($expected->modelKeys()), $this->sortedIds($rows->pluck('id')));

        foreach ($expected as $announcement) {
            $row = $rows->firstWhere('id', $announcement->id);
            $this->assertSame($announcement->title, $row->titulo);
            $this->assertSame($announcement->body, $row->texto);
            $this->assertSame($announcement->starts_at->format('Y-m-d H:i:s'), $row->desde);
            $this->assertSame($announcement->ends_at?->format('Y-m-d H:i:s'), $row->hasta);
            $this->assertSame((int) $announcement->is_pinned, (int) $row->fijado);
            $this->assertSame((int) $announcement->requires_read, (int) $row->exige_lectura);
        }
    }

    public function test_announcements_answer_the_edge_instants(): void
    {
        $this->seedAnnouncements();
        $this->freeze('2026-11-12 10:00:00');

        $titles = $this->rows('v_anuncios_vigentes', 'SGX')->pluck('titulo')->all();

        foreach (['starts-now', 'ends-one-second-late', 'ends-tomorrow', 'all', 'pinned-mandatory', 'sgx-only', 'both', 'sgx-and-other'] as $title) {
            $this->assertContains($title, $titles, $title);
        }
        foreach (['starts-one-second-late', 'ends-now', 'ends-one-second-early', 'utc-trap', 'scheduled', 'expired', 'withdrawn', 'withdrawn-and-expired', 'sdc-only', 'other-only', 'companies-without-companies'] as $title) {
            $this->assertNotContains($title, $titles, $title);
        }
    }

    public function test_a_company_that_is_not_in_the_mapping_gets_no_row_and_audience_all_goes_only_to_the_mapped_ones(): void
    {
        $this->seedAnnouncements();
        $this->seedEvents();
        $this->freeze('2026-11-12 10:00:00');

        foreach (['v_anuncios_vigentes', 'v_eventos_proximos'] as $view) {
            $this->assertEqualsCanonicalizing(['SDC', 'SGX'], DB::table($view)->distinct()->pluck('empresa')->all(), $view);
        }

        $this->assertNotContains('other-only', DB::table('v_anuncios_vigentes')->pluck('titulo')->all());
        $this->assertNotContains('other-only', DB::table('v_eventos_proximos')->pluck('titulo')->all());
    }

    public function test_an_inactive_company_gets_no_row(): void
    {
        $this->seedAnnouncements();
        $this->seedEvents();
        $this->sdc->forceFill(['is_active' => false])->save();
        $this->freeze('2026-11-12 10:00:00');

        foreach (['v_anuncios_vigentes', 'v_eventos_proximos'] as $view) {
            $this->assertSame(0, $this->rows($view, 'SDC')->count(), $view);
            $this->assertGreaterThan(0, $this->rows($view, 'SGX')->count(), $view);
        }
    }

    /** @return array<string, array{string}> */
    public static function instants(): array
    {
        return array_combine($all = [
            '2026-11-12 10:00:00',
            '2026-11-12 00:00:00',
            '2026-11-11 23:59:59',
            '2026-11-12 23:59:59',
            '2026-11-13 00:00:00',
            '2026-12-31 23:59:59',
            '2027-01-01 00:00:00',
            '2027-01-03 12:00:00',
            '2027-01-04 00:00:00',
            '2027-02-28 12:00:00',
            '2027-03-01 00:00:00',
            '2028-02-29 09:00:00',
            '2028-03-02 09:00:00',
            '2029-03-01 00:00:00',
        ], array_map(fn (string $at) => [$at], $all));
    }

    #[DataProvider('instants')]
    public function test_events_match_visible_to_and_the_occurrence_at_every_instant(string $instant): void
    {
        $this->seedEvents();
        $this->freeze($instant);

        foreach (self::companies() as [$property, $code]) {
            $user = $this->userOf($this->{$property});
            $expected = Event::query()->visibleTo($user)->get();
            $rows = $this->rows('v_eventos_proximos', $code);

            $message = "{$code} at {$instant}";
            $this->assertSame($this->sortedIds($expected->modelKeys()), $this->sortedIds($rows->pluck('id')), $message);
            $this->assertSame(
                $this->sortedIds($expected->modelKeys()),
                $this->sortedIds(Event::visibleSoonest($user, 1000, ['*'])->modelKeys()),
                "visibleSoonest, {$message}",
            );

            foreach ($expected as $event) {
                $row = $rows->firstWhere('id', $event->id);
                $label = "{$event->title}, {$message}";

                $this->assertSame($event->title, $row->titulo, $label);
                $this->assertSame($event->starts_at->format('Y-m-d H:i:s'), $row->inicio, $label);
                $this->assertSame($event->ends_at?->format('Y-m-d H:i:s'), $row->fin, $label);
                $this->assertSame((int) $event->all_day, (int) $row->todo_el_dia, $label);
                $this->assertSame((int) $event->repeatsYearly(), (int) $row->se_repite_cada_anio, $label);
                $this->assertSame($event->occurrenceStart()->toDateString(), $row->proxima_fecha, $label);
            }
        }
    }

    public function test_the_next_date_of_the_hard_yearly_cases(): void
    {
        $this->seedEvents();
        $next = function (string $instant, string $title): string {
            $this->freeze($instant);

            return $this->rows('v_eventos_proximos', 'SGX')->firstWhere('titulo', $title)->proxima_fecha;
        };

        $this->assertSame('2027-03-15', $next('2026-11-12 10:00:00', 'yearly-march'));
        $this->assertSame('2026-03-15', $next('2026-03-15 23:59:59', 'yearly-march'));
        $this->assertSame('2027-02-28', $next('2026-11-12 10:00:00', 'yearly-leap-day'));
        $this->assertSame('2028-02-29', $next('2027-03-01 00:00:00', 'yearly-leap-day'));
        $this->assertSame('2026-12-30', $next('2027-01-01 00:00:00', 'yearly-new-year-span'));
        $this->assertSame('2027-12-30', $next('2027-01-04 00:00:00', 'yearly-new-year-span'));
        $this->assertSame('2030-05-05', $next('2026-11-12 10:00:00', 'yearly-first-in-future'));
        $this->assertSame('2026-11-12', $next('2026-11-12 23:59:59', 'yearly-today'));
        $this->assertSame('2026-12-31', $next('2026-12-31 23:59:59', 'yearly-last-day'));
        $this->assertSame('2027-12-31', $next('2027-01-01 00:00:00', 'yearly-last-day'));
        $this->assertSame('2026-11-20', $next('2026-11-12 10:00:00', 'timed-future'));
    }

    public function test_the_events_edge_rows_at_ten_in_the_morning(): void
    {
        $this->seedEvents();
        $this->freeze('2026-11-12 10:00:00');

        $titles = $this->rows('v_eventos_proximos', 'SGX')->pluck('titulo')->all();

        foreach (['timed-ends-soon', 'timed-no-end-today', 'timed-no-end-midnight', 'allday-today', 'allday-multi-current', 'published-now', 'yearly-today', 'yearly-sgx-only', 'sgx-only', 'both'] as $title) {
            $this->assertContains($title, $titles, $title);
        }
        foreach (['timed-over-today', 'timed-ends-now', 'timed-no-end-yesterday', 'allday-yesterday', 'allday-multi-over', 'scheduled', 'published-one-second-late', 'withdrawn', 'yearly-withdrawn', 'sdc-only', 'other-only', 'yearly-other-only', 'yearly-not-all-day'] as $title) {
            $this->assertNotContains($title, $titles, $title);
        }
    }

    public function test_the_kpis_of_each_company_only(): void
    {
        $this->seedKpis();
        $this->freeze('2026-11-12 10:00:00');

        $sgx = $this->rows('v_kpis_empresa', 'SGX')->keyBy('clave');
        $this->assertSame(['margen', 'ventas'], $sgx->keys()->sort()->values()->all());
        $this->assertSame('Cifra ventas', $sgx['ventas']->etiqueta);
        $this->assertEqualsWithDelta(1250000000.5, (float) $sgx['ventas']->valor, 0.001);
        $this->assertEqualsWithDelta(1500000000, (float) $sgx['ventas']->meta, 0.001);
        $this->assertSame('COP', $sgx['ventas']->unidad);
        $this->assertSame('Parcial', $sgx['ventas']->nota);
        $this->assertSame('Octubre', $sgx['ventas']->periodo);
        $this->assertSame('2026-11-11 18:30:00', $sgx['ventas']->corte);
        $this->assertSame('SAP Business One', $sgx['ventas']->fuente);
        $this->assertNull($sgx['margen']->meta);
        $this->assertNull($sgx['margen']->nota);

        $this->assertSame(['ventas'], $this->rows('v_kpis_empresa', 'SDC')->pluck('clave')->all());
        $this->assertSame(['SDC', 'SGX'], DB::table('v_kpis_empresa')->distinct()->orderBy('empresa')->pluck('empresa')->all());
    }
}
