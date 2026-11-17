<?php

namespace App\Management;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Read model of the «Panel gerencial» on «Bases de datos SAP»
 * (odd/tasks/panel-gerencial-sap.md): the current month's sales, margin,
 * receivables by range and top five sellers of one company, read from the
 * read-only views `v_ventas` (connection `sap_ventas`) and `v_cartera`
 * (`sap_cartera`) in three aggregate queries. Every query filters by
 * `empresa`, names its columns and sums in SQL: no row reaches PHP.
 *
 * The month is the one of Bogotá, by `fecha_contabilizacion`. The figures
 * are cached for CACHE_SECONDS per company and month; forget() drops them
 * when a requested update ends (App\Management\SapRefresh).
 *
 * Never throws for the database: a failure is reported, remembered for
 * FAILURE_CACHE_SECONDS (a stopped server must not slow every page) and
 * gives null, so the home page shows «No pudimos leer las cifras de SAP».
 * Who may see the figures is not checked here: see ManagementPanel::ABILITY.
 */
final class SapFigures
{
    public const VENTAS = 'sap_ventas';

    public const CARTERA = 'sap_cartera';

    public const CACHE_SECONDS = 600;

    public const FAILURE_CACHE_SECONDS = 60;

    public const TOP_SELLERS = 5;

    public const TIMEZONE = 'America/Bogota';

    /** `slp_code` of the lines without a seller. */
    public const NO_SELLER = -1;

    private const FAILED = 'failed';

    /**
     * Whether both connections have a host, a user and a database. Without
     * them the panel keeps the figures published through the API.
     */
    public function configured(): bool
    {
        foreach ([self::VENTAS, self::CARTERA] as $connection) {
            foreach (['host', 'username', 'database'] as $key) {
                if (trim((string) config("database.connections.{$connection}.{$key}")) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The figures of the company with this `empresa` code (Company::SAP_CODES)
     * for the current month; null when not configured, for an unknown code
     * or when the views could not be read.
     */
    public function forCompany(string $company, ?CarbonInterface $now = null): ?SapFigureSet
    {
        if (! $this->configured() || ! in_array($company, Company::SAP_CODES, true)) {
            return null;
        }

        $month = self::month($now);
        $key = self::cacheKey($company, $month);
        $cached = Cache::get($key);

        if ($cached instanceof SapFigureSet) {
            return $cached;
        }

        if ($cached === self::FAILED) {
            return null;
        }

        try {
            $figures = $this->read($company, $month);
        } catch (QueryException|PDOException $exception) {
            report($exception);
            Cache::put($key, self::FAILED, self::FAILURE_CACHE_SECONDS);

            return null;
        }

        if (! $figures->rangesMatch()) {
            Log::warning('The receivables ranges of Bases SAP do not add up to the balance.', [
                'empresa' => $company,
                'difference' => $figures->rangesDifference(),
            ]);
        }

        Cache::put($key, $figures, self::CACHE_SECONDS);

        return $figures;
    }

    /** Drops the company's cached figures of the current month, so the next page reads the views. */
    public function forget(string $company, ?CarbonInterface $now = null): void
    {
        Cache::forget(self::cacheKey($company, self::month($now)));
    }

    public static function cacheKey(string $company, CarbonImmutable $month): string
    {
        return "management:sap-figures:{$company}:{$month->format('Y-m')}";
    }

    /** The first day of the current month in Bogotá. */
    private static function month(?CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now())->setTimezone(self::TIMEZONE)->startOfMonth();
    }

    private function read(string $company, CarbonImmutable $month): SapFigureSet
    {
        $sales = $this->sales($company, $month)
            ->selectRaw('SUM(total) AS venta_neta')
            ->selectRaw('SUM(ganancia_bruta) AS ganancia_bruta')
            ->selectRaw('SUM(CASE WHEN ganancia_bruta IS NOT NULL THEN total END) AS venta_con_ganancia')
            ->first();

        $sellers = $this->sales($company, $month)
            ->select('slp_code')
            ->selectRaw('MAX(vendedor) AS vendedor')
            ->selectRaw('SUM(total) AS venta_neta')
            ->groupBy('slp_code')
            ->orderByDesc('venta_neta')
            ->orderBy('slp_code')
            ->limit(self::TOP_SELLERS)
            ->get();

        $receivables = $this->connection(self::CARTERA)->table('v_cartera')
            ->where('empresa', $company)
            ->selectRaw('SUM(saldo) AS saldo');
        foreach (array_keys(SapFigureSet::RANGES) as $column) {
            $receivables->selectRaw("SUM({$column}) AS {$column}");
        }
        $receivables = $receivables->first();

        return new SapFigureSet(
            company: $company,
            month: $month,
            netSales: self::amount($sales?->venta_neta),
            grossProfit: self::amount($sales?->ganancia_bruta),
            salesWithProfit: self::amount($sales?->venta_con_ganancia),
            receivables: self::amount($receivables?->saldo),
            ranges: array_map(
                fn (string $column) => self::amount($receivables?->{$column}),
                array_combine(array_keys(SapFigureSet::RANGES), array_keys(SapFigureSet::RANGES)),
            ),
            topSellers: $sellers->map(fn (object $row) => new SapSeller(
                (int) $row->slp_code,
                self::sellerName((int) $row->slp_code, $row->vendedor),
                self::amount($row->venta_neta),
            ))->values()->all(),
        );
    }

    /** The company's lines of the month: from its first day to the next month's, excluded. */
    private function sales(string $company, CarbonImmutable $month): Builder
    {
        return $this->connection(self::VENTAS)->table('v_ventas')
            ->where('empresa', $company)
            ->where('fecha_contabilizacion', '>=', $month->toDateString())
            ->where('fecha_contabilizacion', '<', $month->addMonth()->toDateString());
    }

    private function connection(string $name): ConnectionInterface
    {
        return DB::connection($name);
    }

    private static function sellerName(int $code, mixed $name): string
    {
        $name = trim((string) $name);

        return match (true) {
            $code === self::NO_SELLER => 'Sin vendedor',
            $name === '' => "Vendedor {$code}",
            default => $name,
        };
    }

    /** MySQL gives DECIMAL sums as strings, and a sum of no rows as null. */
    private static function amount(mixed $value): float
    {
        return $value === null ? 0.0 : (float) $value;
    }
}
