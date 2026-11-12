<?php

namespace App\Agent\Views;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;

/**
 * The read-only views the multi-agent system reads (docs of the connection
 * plan «portal-ryr», section 3). The Spanish column names are the contract
 * with the agent; everything else is English.
 *
 * - `aux_empresa` is a helper with no `v_` prefix on purpose: the database
 *   user of the agent gets SELECT on `v_*` only, and the views run with the
 *   privileges of their definer.
 * - Every view carries `empresa` ('SGX' for the company `segurex`, 'SDC' for
 *   `derivados`; any other or inactive company produces no rows).
 * - `v_tareas_pendientes` is the only per-person view (`usuario_correo`).
 * - Visibility mirrors the Eloquent scopes (`visibleTo`, `scopeUpcoming`,
 *   `WorkItem::scopeVisibleTo`, `ItStatusBoard`); the parity tests compare
 *   them on the same data. The code wins over the plan where they differ.
 * - Time never comes from the session time zone, see {@see Dialect}.
 *
 * The SQL is portable; {@see Dialect} holds the few differences between
 * MariaDB/MySQL and SQLite, which is what the test suite runs on.
 */
final class AgentViews
{
    /** The database keeps America/Bogota times, which are UTC-5 all year (no DST). */
    public const UTC_OFFSET_HOURS = -5;

    /** Hours a failure report of an app counts when Configuración has no value (config/portal.php). */
    public const DEFAULT_API_STATUS_TTL_HOURS = 2;

    public const HELPER = 'aux_empresa';

    private const NAMES = [
        'v_anuncios_vigentes',
        'v_eventos_proximos',
        'v_tareas_pendientes',
        'v_estado_ti',
        'v_kpis_empresa',
    ];

    /**
     * The views the agent may read, in creation order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * The column names of the select list of a view, read from its SQL.
     *
     * @return list<string>
     */
    public static function columns(string $view, string $dialect = Dialect::SQLITE): array
    {
        $sql = self::definitions($dialect)[$view] ?? throw new InvalidArgumentException("Unknown view [{$view}].");
        $lists = self::selectLists($sql);

        foreach ($lists as $other) {
            if ($other !== $lists[0]) {
                throw new LogicException("The branches of [{$view}] do not have the same columns.");
            }
        }

        return $lists[0];
    }

    /**
     * The SELECT behind each object, helper first, in creation order.
     *
     * @param  string|null  $fixedLocalNow  A local `Y-m-d H:i:s` moment instead of the clock (tests)
     * @return array<string, string>
     */
    public static function definitions(string $dialect, ?string $fixedLocalNow = null): array
    {
        $d = Dialect::named($dialect, $fixedLocalNow);

        return [
            self::HELPER => self::helper(),
            'v_anuncios_vigentes' => self::announcements($d),
            'v_eventos_proximos' => self::events($d),
            'v_tareas_pendientes' => self::tasks($d),
            'v_estado_ti' => self::itStatus($d),
            'v_kpis_empresa' => self::kpis(),
        ];
    }

    /**
     * (Re)creates the helper and the views; running it again changes nothing.
     * Does nothing on a driver that is not MariaDB/MySQL or SQLite.
     */
    public static function install(ConnectionInterface $connection, ?string $fixedLocalNow = null): void
    {
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return;
        }

        self::drop($connection);

        // MariaDB/MySQL: the view runs as its definer, so the read-only user needs no grant on the tables.
        $create = $driver === 'sqlite' ? 'CREATE VIEW' : 'CREATE ALGORITHM = UNDEFINED SQL SECURITY DEFINER VIEW';

        foreach (self::definitions($driver, $fixedLocalNow) as $name => $sql) {
            $connection->statement("{$create} {$name} AS {$sql}");
        }
    }

    /** Drops the views, then the helper. */
    public static function drop(ConnectionInterface $connection): void
    {
        foreach (array_reverse([self::HELPER, ...self::NAMES]) as $name) {
            $connection->statement("DROP VIEW IF EXISTS {$name}");
        }
    }

    private static function helper(): string
    {
        return <<<'SQL'
SELECT c.id AS company_id,
       CASE c.slug WHEN 'segurex' THEN 'SGX' WHEN 'derivados' THEN 'SDC' END AS empresa
FROM companies c
WHERE c.is_active = 1 AND c.slug IN ('segurex', 'derivados')
SQL;
    }

    /** Same rule as Announcement::scopeInStatus(published) + scopeForCompanyId(). */
    private static function announcements(Dialect $d): string
    {
        $now = $d->now();

        return <<<SQL
SELECT ae.empresa AS empresa,
       a.id AS id,
       a.title AS titulo,
       a.body AS texto,
       a.starts_at AS desde,
       a.ends_at AS hasta,
       CASE WHEN a.is_pinned = 1 THEN 1 ELSE 0 END AS fijado,
       CASE WHEN a.requires_read = 1 THEN 1 ELSE 0 END AS exige_lectura
FROM aux_empresa ae
JOIN announcements a ON (a.audience = 'all'
    OR EXISTS (SELECT 1 FROM announcement_company ac WHERE ac.announcement_id = a.id AND ac.company_id = ae.company_id))
WHERE a.withdrawn_at IS NULL
  AND a.starts_at <= {$now}
  AND (a.ends_at IS NULL OR a.ends_at > {$now})
SQL;
    }

    /**
     * Same rule as Event::scopeInStatus(published) (live and upcoming) +
     * scopeForCompanyId(); `proxima_fecha` is Event::occurrenceStart() as a
     * date. Three levels: the candidate dates of a yearly event (last, this
     * and next year), the one in force today, and the visibility.
     */
    private static function events(Dialect $d): string
    {
        $now = $d->now();
        $today = $d->today();
        $startOfToday = $d->startOfToday();

        $first = $d->dateOf('e.starts_at');
        $last = $d->dateOf('COALESCE(e.ends_at, e.starts_at)');
        $diff = $d->dayDiff($last, $first);
        $month = $d->month('e.starts_at');
        $day = $d->dayOfMonth('e.starts_at');
        $thisYear = $d->year($today);

        // The same month and day in another year; 29 February becomes 28 February in a common year.
        $onYear = function (string $offset) use ($d, $month, $day, $thisYear): string {
            $year = "({$thisYear} {$offset})";
            $leap = "({$year} % 4 = 0 AND ({$year} % 100 <> 0 OR {$year} % 400 = 0))";

            return $d->makeDate($year, $month, "CASE WHEN {$month} = 2 AND {$day} = 29 AND NOT {$leap} THEN 28 ELSE {$day} END");
        };

        $previous = $onYear('- 1');
        $current = $onYear('+ 0');
        $next = $onYear('+ 1');
        $previousEnds = $d->addDays('x.previous_year', 'x.length_days');
        $currentEnds = $d->addDays('x.this_year', 'x.length_days');

        return <<<SQL
SELECT ae.empresa AS empresa,
       v.id AS id,
       v.title AS titulo,
       v.description AS descripcion,
       v.location AS lugar,
       v.starts_at AS inicio,
       v.ends_at AS fin,
       CASE WHEN v.all_day = 1 THEN 1 ELSE 0 END AS todo_el_dia,
       CASE WHEN v.all_day = 1 AND v.repeats_yearly = 1 THEN 1 ELSE 0 END AS se_repite_cada_anio,
       CASE WHEN v.all_day = 1 AND v.repeats_yearly = 1
            THEN (CASE WHEN v.occurrence < v.first_day THEN v.first_day ELSE v.occurrence END)
            ELSE {$d->dateOf('v.starts_at')} END AS proxima_fecha
FROM aux_empresa ae
JOIN (
    SELECT x.id, x.title, x.description, x.location, x.starts_at, x.ends_at, x.all_day, x.repeats_yearly,
           x.audience, x.published_at, x.withdrawn_at, x.first_day,
           CASE WHEN {$previousEnds} >= {$today} THEN x.previous_year
                WHEN {$currentEnds} >= {$today} THEN x.this_year
                ELSE x.next_year END AS occurrence
    FROM (
        SELECT e.id, e.title, e.description, e.location, e.starts_at, e.ends_at, e.all_day, e.repeats_yearly,
               e.audience, e.published_at, e.withdrawn_at,
               {$first} AS first_day,
               CASE WHEN {$diff} < 0 THEN 0 ELSE {$diff} END AS length_days,
               {$previous} AS previous_year,
               {$current} AS this_year,
               {$next} AS next_year
        FROM events e
    ) x
) v ON (v.audience = 'all'
    OR EXISTS (SELECT 1 FROM event_company ec WHERE ec.event_id = v.id AND ec.company_id = ae.company_id))
WHERE v.withdrawn_at IS NULL
  AND v.published_at <= {$now}
  AND ((v.ends_at IS NULL AND v.starts_at >= {$startOfToday})
    OR (v.all_day = 1 AND v.ends_at IS NOT NULL AND v.ends_at >= {$startOfToday})
    OR (v.all_day = 0 AND v.ends_at IS NOT NULL AND v.ends_at > {$now})
    OR (v.all_day = 1 AND v.repeats_yearly = 1))
SQL;
    }

    /**
     * Same rule as WorkItem::scopeVisibleTo() (the account's own pending
     * items of a live integration with `work:write` and a catalog app), for
     * the accounts that may use the panel: not a guest, not an emergency
     * account, with an active mapped company and a UPN. `usuario_correo` is
     * the account's UPN (`users.upn`, kept at each Microsoft sign-in), the
     * same value the panel token carries in `sub`; an account without one
     * is left out, never matched by its mail.
     */
    private static function tasks(Dialect $d): string
    {
        $now = $d->now();
        $writes = $d->jsonArrayContains('i.abilities', 'work:write');

        return <<<SQL
SELECT ae.empresa AS empresa,
       LOWER(u.upn) AS usuario_correo,
       CASE w.kind WHEN 'task' THEN 'tarea' WHEN 'approval' THEN 'aprobacion' END AS tipo,
       w.title AS titulo,
       w.due_at AS vence,
       CASE WHEN w.due_at IS NOT NULL AND w.due_at < {$now} THEN 1 ELSE 0 END AS vencida,
       w.url AS enlace,
       CASE WHEN w.kind = 'task' AND w.completable_from_portal = 1
             AND i.signing_secret IS NOT NULL AND i.signing_secret_hint IS NOT NULL AND i.app_base_url IS NOT NULL
            THEN 1 ELSE 0 END AS puede_completar
FROM work_items w
JOIN users u ON LOWER(u.tenant_id) = w.assignee_tenant_id AND LOWER(u.azure_oid) = w.assignee_oid
JOIN integrations i ON i.id = w.integration_id
JOIN aux_empresa ae ON ae.company_id = u.company_id
WHERE w.deleted_at IS NULL
  AND w.status = 'pending'
  AND COALESCE(u.is_guest, 0) = 0
  AND COALESCE(u.is_emergency, 0) = 0
  AND u.upn IS NOT NULL AND TRIM(u.upn) <> ''
  AND i.revoked_at IS NULL
  AND i.app_id IS NOT NULL
  AND {$writes}
SQL;
    }

    /**
     * The manual services of the company (ItService, every status) and the
     * apps the company gets that ItStatusBoard lists: in maintenance, or with
     * a degraded/down report newer than the hours of Configuración. The access
     * rules by group are PHP-only and not replicated: an app the company gets
     * but a person may not open is still listed.
     */
    private static function itStatus(Dialect $d): string
    {
        $stored = $d->castInt('(SELECT ps.`value` FROM portal_settings ps WHERE ps.`key` = \'api_status_ttl_hours\')');
        $default = AgentViews::DEFAULT_API_STATUS_TTL_HOURS;
        $hours = "(CASE WHEN COALESCE({$stored}, {$default}) < 1 THEN 1 ELSE COALESCE({$stored}, {$default}) END)";
        $cutoff = $d->addHours($d->now(), "- {$hours}");

        return <<<SQL
SELECT ae.empresa AS empresa,
       'servicio' AS tipo,
       s.name AS nombre,
       CASE WHEN s.status IN ('operational', 'degraded', 'down') THEN s.status ELSE 'degraded' END AS estado,
       s.message AS mensaje,
       s.updated_at AS actualizado
FROM aux_empresa ae
JOIN it_services s ON s.company_id = ae.company_id
UNION ALL
SELECT ae.empresa AS empresa,
       'app' AS tipo,
       p.name AS nombre,
       CASE WHEN p.status = 'maintenance' THEN 'maintenance'
            WHEN p.external_status = 'down' THEN 'down'
            ELSE 'degraded' END AS estado,
       CASE WHEN p.status = 'maintenance' THEN p.maintenance_message ELSE p.external_status_message END AS mensaje,
       CASE WHEN p.status = 'maintenance' THEN p.updated_at ELSE p.external_status_at END AS actualizado
FROM aux_empresa ae
JOIN apps p ON p.status <> 'hidden'
    AND (p.available_to_all_companies = 1
        OR EXISTS (SELECT 1 FROM app_companies pc WHERE pc.app_id = p.id AND pc.company_id = ae.company_id))
WHERE p.status = 'maintenance'
   OR (p.external_status IN ('degraded', 'down') AND p.external_status_at > {$cutoff})
SQL;
    }

    private static function kpis(): string
    {
        return <<<'SQL'
SELECT ae.empresa AS empresa,
       k.`key` AS clave,
       k.label AS etiqueta,
       k.`value` AS valor,
       k.unit AS unidad,
       k.goal AS meta,
       k.note AS nota,
       k.period_label AS periodo,
       k.as_of AS corte,
       k.source_label AS fuente
FROM aux_empresa ae
JOIN company_kpis k ON k.company_id = ae.company_id
SQL;
    }

    /**
     * The column names of every top-level SELECT (the branches of a UNION):
     * the text between SELECT and FROM at parenthesis depth 0, split at
     * depth-0 commas, each item ending in `AS name`.
     *
     * @return list<list<string>>
     */
    private static function selectLists(string $sql): array
    {
        $lists = [];
        $items = [];
        $buffer = '';
        $depth = 0;
        $collecting = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" || $char === '`') {
                $end = strpos($sql, $char, $i + 1);
                $end = $end === false ? $length - 1 : $end;
                $buffer .= substr($sql, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($depth === 0 && ctype_alpha($char) && ($i === 0 || ! (ctype_alnum($sql[$i - 1]) || $sql[$i - 1] === '_'))
                && preg_match('/\G(SELECT|FROM)\b/', $sql, $match, 0, $i) === 1) {
                if ($match[1] === 'SELECT') {
                    $collecting = true;
                    $items = [];
                    $buffer = '';
                } elseif ($collecting) {
                    $items[] = $buffer;
                    $lists[] = array_map(self::columnName(...), $items);
                    $collecting = false;
                }

                $i += strlen($match[1]) - 1;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($collecting) {
                if ($char === ',' && $depth === 0) {
                    $items[] = $buffer;
                    $buffer = '';
                } else {
                    $buffer .= $char;
                }
            }
        }

        if ($lists === []) {
            throw new LogicException('No SELECT list found.');
        }

        return $lists;
    }

    private static function columnName(string $item): string
    {
        if (preg_match('/\bAS\s+([a-z_][a-z0-9_]*)\s*\z/i', trim($item), $match) !== 1) {
            throw new LogicException("A column without an explicit name: [{$item}].");
        }

        return $match[1];
    }
}
