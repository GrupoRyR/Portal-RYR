<?php

namespace Tests\Feature\Agent\Views;

use App\Agent\Views\AgentViews;
use App\Models\App;
use App\Models\Event;
use App\Models\ItService;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\Views\Concerns\BuildsAgentWorld;
use Tests\TestCase;

/**
 * Runs the views on a real MariaDB. Skipped unless configured: the normal
 * suite runs on SQLite, where the MariaDB flavour of the SQL never executes.
 *
 * It needs an EMPTY, throw-away MariaDB 10.6+ database (the test refuses to
 * run if it finds any table) and these environment variables:
 *
 *   AGENT_TEST_MARIADB_HOST, AGENT_TEST_MARIADB_DATABASE,
 *   AGENT_TEST_MARIADB_USER, AGENT_TEST_MARIADB_PASSWORD,
 *   AGENT_TEST_MARIADB_PORT (optional, 3306)
 *
 * It creates the portal schema with the migrations (which create the views),
 * seeds a few rows relative to the real clock and wipes the database after.
 */
class AgentViewsMariaDbTest extends TestCase
{
    use BuildsAgentWorld;

    private const CONNECTION = 'agent_mariadb';

    private ?Connection $db = null;

    private string $previousDefault;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeded integrations encrypt their signing secret: a throwaway key when the run has none,
        // so the documented command works as written.
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            $this->app->forgetInstance('encrypter');
        }

        $host = getenv('AGENT_TEST_MARIADB_HOST');
        $database = getenv('AGENT_TEST_MARIADB_DATABASE');
        $user = getenv('AGENT_TEST_MARIADB_USER');

        if (! $host || ! $database || ! $user) {
            $this->markTestSkipped('No test MariaDB (AGENT_TEST_MARIADB_*): skipped.');
        }

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'mariadb',
            'host' => $host,
            'port' => getenv('AGENT_TEST_MARIADB_PORT') ?: '3306',
            'database' => $database,
            'username' => $user,
            'password' => (string) getenv('AGENT_TEST_MARIADB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]]);

        $connection = DB::connection(self::CONNECTION);

        if ($connection->select('SHOW FULL TABLES') !== []) {
            $this->markTestSkipped('The test database is not empty: it is not touched.');
        }

        $this->db = $connection;
        $this->previousDefault = config('database.default');
        config(['database.default' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);

        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
        $this->makeCompanies();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            // Views too: without --drop-views they stay, and the next test finds the database «not empty».
            Artisan::call('db:wipe', ['--database' => self::CONNECTION, '--drop-views' => true, '--force' => true]);
            DB::setDefaultConnection($this->previousDefault);
            config(['database.default' => $this->previousDefault]);
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function titles(string $view, string $empresa = 'SGX', string $column = 'titulo'): array
    {
        $titles = $this->db->table($view)->where('empresa', $empresa)->pluck($column)->all();
        sort($titles);

        return $titles;
    }

    public function test_every_view_selects_and_the_migration_can_run_twice(): void
    {
        AgentViews::install($this->db);

        foreach (AgentViews::names() as $view) {
            $this->assertSame(AgentViews::columns($view), array_column($this->db->select("SHOW COLUMNS FROM {$view}"), 'Field'), $view);
            $this->db->select("SELECT * FROM {$view} LIMIT 1");
        }
    }

    public function test_announcements_and_events_follow_local_time_whatever_the_session_time_zone(): void
    {
        $local = CarbonImmutable::now('America/Bogota');
        $at = fn (string $modifier) => $local->modify($modifier)->format('Y-m-d H:i:s');

        $this->announcement('started-3h-ago', ['starts_at' => $at('-3 hours')]);
        $this->announcement('starts-in-3h', ['starts_at' => $at('+3 hours')]);
        $this->announcement('ended-1h-ago', ['starts_at' => $at('-1 day'), 'ends_at' => $at('-1 hour')]);
        $this->announcement('other-only', [], [$this->other]);
        $this->event('ends-in-3h', ['starts_at' => $at('-1 hour'), 'ends_at' => $at('+3 hours')]);
        $this->event('ended-1h-ago', ['starts_at' => $at('-3 hours'), 'ends_at' => $at('-1 hour')]);
        $this->event('yearly-leap-day', ['all_day' => true, 'repeats_yearly' => true, 'starts_at' => '2024-02-29 00:00:00']);

        foreach (['+00:00', '-12:00', '+09:00'] as $zone) {
            $this->db->statement("SET SESSION time_zone = '{$zone}'");

            $this->assertSame(['started-3h-ago'], $this->titles('v_anuncios_vigentes'), $zone);
            $this->assertSame(['ends-in-3h', 'yearly-leap-day'], $this->titles('v_eventos_proximos'), $zone);
            $this->assertSame(['started-3h-ago'], $this->titles('v_anuncios_vigentes', 'SDC'), $zone);
        }

        $leap = $this->db->table('v_eventos_proximos')->where('empresa', 'SGX')->where('titulo', 'yearly-leap-day')->value('proxima_fecha');
        $this->assertSame(Event::query()->where('title', 'yearly-leap-day')->firstOrFail()->occurrenceStart()->toDateString(), $leap);
    }

    public function test_tasks_are_per_person_and_only_for_live_integrations(): void
    {
        $ana = $this->microsoftUser($this->sgx, 'ana.mail@example.test', ['upn' => 'Ana.Perez@Example.test']);
        $beto = $this->microsoftUser($this->sgx, 'beto@example.test');
        $guest = $this->microsoftUser($this->sgx, 'invitado@example.test', ['is_guest' => true]);
        $tasks = App::factory()->create(['slug' => 'tareas', 'name' => 'Tareas', 'url' => 'https://tareas.segurex.com.co']);
        $live = $this->workIntegration('Tareas', $tasks);
        $noAbility = $this->workIntegration('Sin habilidad', $tasks, ['abilities' => ['catalog:read']]);

        foreach ([$ana, $beto, $guest] as $user) {
            foreach ([['pending', $live], ['no-ability', $noAbility]] as [$what, $integration]) {
                WorkItem::factory()->create([
                    'integration_id' => $integration->id,
                    'title' => "{$user->id}:{$what}",
                    'assignee_tenant_id' => strtolower($user->tenant_id),
                    'assignee_oid' => strtolower($user->azure_oid),
                ]);
            }
        }

        $this->assertSame(["{$ana->id}:pending"], $this->db->table('v_tareas_pendientes')->where('usuario_correo', 'ana.perez@example.test')->pluck('titulo')->all());
        $this->assertSame(["{$beto->id}:pending"], $this->db->table('v_tareas_pendientes')->where('usuario_correo', 'beto@example.test')->pluck('titulo')->all());
        $this->assertSame(0, $this->db->table('v_tareas_pendientes')->where('usuario_correo', 'invitado@example.test')->count());
    }

    public function test_it_status_and_kpis(): void
    {
        $local = CarbonImmutable::now('America/Bogota');
        ItService::factory()->create(['company_id' => $this->sgx->id, 'name' => 'Correo', 'status' => 'down', 'message' => 'Caído']);
        ItService::factory()->create(['company_id' => $this->other->id, 'name' => 'Otra', 'status' => 'down']);
        App::factory()->create(['slug' => 'en-mantenimiento', 'name' => 'En mantenimiento', 'url' => 'https://a.segurex.com.co', 'status' => App::STATUS_MAINTENANCE, 'available_to_all_companies' => true]);
        App::factory()->create(['slug' => 'con-falla', 'name' => 'Con falla', 'url' => 'https://b.segurex.com.co', 'external_status' => 'down', 'external_status_at' => $local->subHour()->format('Y-m-d H:i:s'), 'available_to_all_companies' => true]);
        App::factory()->create(['slug' => 'falla-vieja', 'name' => 'Falla vieja', 'url' => 'https://c.segurex.com.co', 'external_status' => 'down', 'external_status_at' => $local->subHours(3)->format('Y-m-d H:i:s'), 'available_to_all_companies' => true]);
        $this->seedKpis();

        $this->assertSame(['Con falla', 'Correo', 'En mantenimiento'], $this->titles('v_estado_ti', 'SGX', 'nombre'));
        $this->assertSame(['Con falla', 'En mantenimiento'], $this->titles('v_estado_ti', 'SDC', 'nombre'));
        $this->assertSame(['margen', 'ventas'], $this->titles('v_kpis_empresa', 'SGX', 'clave'));
        $this->assertSame(['SDC', 'SGX'], $this->db->table('v_kpis_empresa')->distinct()->orderBy('empresa')->pluck('empresa')->all());
    }
}
