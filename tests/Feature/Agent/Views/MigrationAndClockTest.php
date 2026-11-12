<?php

namespace Tests\Feature\Agent\Views;

use App\Agent\Views\AgentViews;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\Views\Concerns\BuildsAgentWorld;
use Tests\TestCase;

/**
 * The migration (idempotent, reversible) and the real clock of the views:
 * UTC minus five hours, whatever the time zone of the PHP process or of the
 * session.
 */
class MigrationAndClockTest extends TestCase
{
    use BuildsAgentWorld;
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require base_path('database/migrations/2026_11_12_000001_create_agent_views.php');
    }

    /** @return list<string> */
    private function viewNames(): array
    {
        return DB::table('sqlite_master')->where('type', 'view')->orderBy('name')->pluck('name')->all();
    }

    public function test_the_migration_creates_the_helper_and_the_five_views(): void
    {
        $expected = [AgentViews::HELPER, ...AgentViews::names()];
        sort($expected);

        $this->assertSame($expected, $this->viewNames());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->makeCompanies();
        $this->seedAnnouncements();
        $this->seedEvents();
        $this->seedKpis();
        $this->seedItStatus();

        $snapshot = fn () => collect(AgentViews::names())->mapWithKeys(fn (string $view) => [$view => DB::table($view)->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, $snapshot());
        $this->assertNotEmpty($before['v_anuncios_vigentes']);
        $this->assertNotEmpty($before['v_eventos_proximos']);
        $this->assertNotEmpty($before['v_estado_ti']);
        $this->assertNotEmpty($before['v_kpis_empresa']);
        $this->assertCount(6, $this->viewNames());
    }

    public function test_down_drops_every_view_and_up_brings_them_back(): void
    {
        $this->migration()->down();
        $this->assertSame([], $this->viewNames());

        $this->migration()->down();
        $this->assertSame([], $this->viewNames());

        $this->migration()->up();
        $this->assertCount(6, $this->viewNames());
    }

    public function test_the_views_have_exactly_the_columns_of_the_definitions(): void
    {
        foreach (AgentViews::names() as $view) {
            $columns = array_column(DB::select("PRAGMA table_info({$view})"), 'name');

            $this->assertSame(AgentViews::columns($view), $columns, $view);
        }
    }

    public function test_the_real_clock_is_local_time_five_hours_behind_utc(): void
    {
        $local = CarbonImmutable::now('America/Bogota');
        $viewNow = CarbonImmutable::parse(DB::selectOne("SELECT datetime('now', '-5 hours') AS now")->now, 'America/Bogota');

        $this->assertEqualsWithDelta($local->getTimestamp(), $viewNow->getTimestamp(), 5);
    }

    public function test_announcements_and_events_follow_local_time_not_utc(): void
    {
        $this->makeCompanies();
        // Another time zone for the PHP process must not matter to the views.
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $local = CarbonImmutable::now('America/Bogota');
            $at = fn (string $modifier) => $local->modify($modifier)->format('Y-m-d H:i:s');

            // Three hours ahead of local time is already past in UTC (five hours ahead): a view on UTC shows them.
            $this->announcement('started-3h-ago', ['starts_at' => $at('-3 hours')]);
            $this->announcement('starts-in-3h', ['starts_at' => $at('+3 hours')]);
            $this->announcement('ends-in-3h', ['starts_at' => $at('-1 day'), 'ends_at' => $at('+3 hours')]);
            $this->announcement('ended-1h-ago', ['starts_at' => $at('-1 day'), 'ends_at' => $at('-1 hour')]);

            $this->event('ends-in-3h', ['starts_at' => $at('-1 hour'), 'ends_at' => $at('+3 hours')]);
            $this->event('ended-1h-ago', ['starts_at' => $at('-3 hours'), 'ends_at' => $at('-1 hour')]);
            $this->event('starts-in-3h', ['starts_at' => $at('+3 hours'), 'ends_at' => $at('+4 hours')]);

            $announcements = $this->rows('v_anuncios_vigentes', 'SGX')->pluck('titulo')->all();
            $this->assertEqualsCanonicalizing(['started-3h-ago', 'ends-in-3h'], $announcements);

            $events = $this->rows('v_eventos_proximos', 'SGX')->pluck('titulo')->all();
            $this->assertEqualsCanonicalizing(['ends-in-3h', 'starts-in-3h'], $events);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_the_local_day_starts_at_local_midnight(): void
    {
        $this->makeCompanies();
        $local = CarbonImmutable::now('America/Bogota');

        // An all-day event of yesterday is over for local time even when UTC still shows «today».
        $this->event('all-day-today', ['all_day' => true, 'starts_at' => $local->format('Y-m-d').' 00:00:00']);
        $this->event('all-day-yesterday', ['all_day' => true, 'starts_at' => $local->subDay()->format('Y-m-d').' 00:00:00']);
        $this->event('timed-no-end-earlier-today', ['starts_at' => $local->format('Y-m-d').' 00:00:00']);

        $this->assertEqualsCanonicalizing(
            ['all-day-today', 'timed-no-end-earlier-today'],
            $this->rows('v_eventos_proximos', 'SGX')->pluck('titulo')->all(),
        );
    }
}
