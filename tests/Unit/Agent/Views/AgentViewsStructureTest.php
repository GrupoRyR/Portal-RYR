<?php

namespace Tests\Unit\Agent\Views;

use App\Agent\Views\AgentViews;
use App\Agent\Views\Dialect;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shape of the agent views: the contract of the connection plan
 * (columns, `empresa` everywhere, the one per-person column) and nothing
 * the plan lists under «Lo que NO se expone».
 */
class AgentViewsStructureTest extends TestCase
{
    /** The columns of the plan, section 3. */
    private const PLAN = [
        'v_anuncios_vigentes' => ['empresa', 'id', 'titulo', 'texto', 'desde', 'hasta', 'fijado', 'exige_lectura'],
        'v_eventos_proximos' => ['empresa', 'id', 'titulo', 'descripcion', 'lugar', 'inicio', 'fin', 'todo_el_dia', 'se_repite_cada_anio', 'proxima_fecha'],
        'v_tareas_pendientes' => ['empresa', 'usuario_correo', 'tipo', 'titulo', 'vence', 'vencida', 'enlace', 'puede_completar'],
        'v_estado_ti' => ['empresa', 'tipo', 'nombre', 'estado', 'mensaje', 'actualizado'],
        'v_kpis_empresa' => ['empresa', 'clave', 'etiqueta', 'valor', 'unidad', 'meta', 'nota', 'periodo', 'corte', 'fuente'],
    ];

    /** Never a column of an agent view. */
    private const FORBIDDEN = [
        'author_id', 'autor', 'image_path', 'imagen', 'read_at', 'leido_en', 'user_id', 'usuario_id', 'person_id',
        'token', 'secret', 'signing_secret', 'password', 'remember_token', 'azure_oid', 'oid', 'tenant_id',
        'assignee_oid', 'assignee_tenant_id', 'company_id', 'integration_id', 'hire_date', 'email', 'external_id',
    ];

    /** Tables and views the agent must never read through its views. */
    private const FORBIDDEN_TABLES = [
        'announcement_reads', 'activity_log', 'app_launches', 'user_favorites', 'user_groups', 'people', 'sessions',
        'personal_access_tokens', 'mcp_connections', 'push_subscriptions', 'directory_entries', 'oauth_',
    ];

    /** @return array<string, array{string}> */
    public static function dialects(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'mariadb' => ['mariadb']];
    }

    public function test_the_views_are_the_five_of_the_plan_in_creation_order(): void
    {
        $this->assertSame(array_keys(self::PLAN), AgentViews::names());
        $this->assertSame(['aux_empresa', ...array_keys(self::PLAN)], array_keys(AgentViews::definitions('sqlite')));
    }

    public function test_only_the_helper_lacks_the_view_prefix(): void
    {
        foreach (AgentViews::names() as $name) {
            $this->assertStringStartsWith('v_', $name);
        }

        $this->assertStringStartsNotWith('v_', AgentViews::HELPER);
    }

    #[DataProvider('dialects')]
    public function test_the_columns_are_exactly_the_plan_ones(string $dialect): void
    {
        foreach (self::PLAN as $view => $columns) {
            $this->assertSame($columns, AgentViews::columns($view, $dialect), $view);
        }
    }

    public function test_the_helper_is_not_a_view_for_the_agent(): void
    {
        $this->assertSame(['company_id', 'empresa'], AgentViews::columns(AgentViews::HELPER));
    }

    public function test_an_unknown_view_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AgentViews::columns('v_nada');
    }

    #[DataProvider('dialects')]
    public function test_no_definition_selects_every_column(string $dialect): void
    {
        foreach (AgentViews::definitions($dialect) as $name => $sql) {
            $this->assertDoesNotMatchRegularExpression('/\*/', $sql, "{$name} has a star");
        }
    }

    public function test_every_view_has_the_company_and_only_the_tasks_have_the_person(): void
    {
        foreach (AgentViews::names() as $view) {
            $this->assertContains('empresa', AgentViews::columns($view), $view);
            $this->assertSame($view === 'v_tareas_pendientes', in_array('usuario_correo', AgentViews::columns($view), true), $view);
        }
    }

    public function test_no_view_exposes_a_forbidden_column(): void
    {
        foreach (AgentViews::names() as $view) {
            $this->assertSame([], array_intersect(AgentViews::columns($view), self::FORBIDDEN), $view);
        }
    }

    public function test_no_view_reads_a_table_the_plan_keeps_hidden(): void
    {
        foreach (AgentViews::definitions('sqlite') as $name => $sql) {
            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertStringNotContainsString($table, $sql, "{$name} reads {$table}");
            }
        }
    }

    public function test_the_person_is_never_read_outside_the_tasks(): void
    {
        foreach (AgentViews::definitions('sqlite') as $name => $sql) {
            $reads = preg_match('/\b(users|people)\b/', $sql) === 1;

            $this->assertSame($name === 'v_tareas_pendientes', $reads, $name);
        }
    }

    #[DataProvider('dialects')]
    public function test_the_columns_come_out_the_same_in_every_dialect(string $dialect): void
    {
        foreach (AgentViews::names() as $view) {
            $this->assertSame(AgentViews::columns($view, 'sqlite'), AgentViews::columns($view, $dialect), $view);
        }
    }

    #[DataProvider('dialects')]
    public function test_no_definition_depends_on_the_session_time_zone(string $dialect): void
    {
        foreach (AgentViews::definitions($dialect) as $name => $sql) {
            // SQLite's «now» is UTC; it only counts with the offset next to it.
            $this->assertSame(substr_count($sql, "'now'"), substr_count($sql, "'now', '-5 hours'"), $name);

            $withoutStrings = preg_replace("/'[^']*'/", "''", $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(NOW|CURDATE|CURRENT_DATE|CURRENT_TIMESTAMP|CURTIME|LOCALTIME|LOCALTIMESTAMP|SYSDATE|FROM_UNIXTIME|CONVERT_TZ)\b/i', $withoutStrings, $name);
        }
    }

    public function test_the_clock_is_utc_minus_the_offset_in_one_place(): void
    {
        $this->assertSame(-5, AgentViews::UTC_OFFSET_HOURS);

        $mysql = AgentViews::definitions('mysql')['v_anuncios_vigentes'];
        $this->assertStringContainsString('(UTC_TIMESTAMP() - INTERVAL 5 HOUR)', $mysql);
        $this->assertStringNotContainsString('INTERVAL 6 HOUR', $mysql);

        $sqlite = AgentViews::definitions('sqlite')['v_anuncios_vigentes'];
        $this->assertStringContainsString("datetime('now', '-5 hours')", $sqlite);
    }

    public function test_a_fixed_moment_replaces_the_clock(): void
    {
        $sql = AgentViews::definitions('sqlite', '2026-11-12 10:00:00')['v_eventos_proximos'];

        $this->assertStringContainsString("datetime('2026-11-12 10:00:00')", $sql);
        $this->assertStringNotContainsString("'now'", $sql);
        $this->assertStringContainsString("TIMESTAMP('2026-11-12 10:00:00')", AgentViews::definitions('mysql', '2026-11-12 10:00:00')['v_eventos_proximos']);
    }

    public function test_a_fixed_moment_must_be_a_local_datetime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dialect::named('sqlite', '2026-11-12');
    }

    public function test_an_unsupported_dialect_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AgentViews::definitions('pgsql');
    }

    public function test_mysql_views_run_as_their_definer_and_are_created_helper_first(): void
    {
        $statements = [];
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $connection->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements) {
            $statements[] = $sql;

            return true;
        });

        AgentViews::install($connection);

        $drops = array_slice($statements, 0, 6);
        $this->assertSame('DROP VIEW IF EXISTS v_kpis_empresa', $drops[0]);
        $this->assertSame('DROP VIEW IF EXISTS aux_empresa', $drops[5]);

        $creates = array_slice($statements, 6);
        $this->assertCount(6, $creates);
        $this->assertStringStartsWith('CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW aux_empresa AS ', $creates[0]);
        $this->assertStringStartsWith('CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW v_anuncios_vigentes AS ', $creates[1]);
    }

    public function test_another_driver_gets_no_views(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldNotReceive('statement');

        AgentViews::install($connection);
        $this->addToAssertionCount(1);
    }

    public function test_the_default_hours_of_a_failure_report_are_those_of_the_portal(): void
    {
        $this->assertSame(config('portal.api_status_ttl_hours'), AgentViews::DEFAULT_API_STATUS_TTL_HOURS);
    }
}
