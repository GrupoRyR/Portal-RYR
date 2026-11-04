<?php

namespace App\Markets;

use App\Models\MarketRate;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies the market figures of the home band into `market_rates`
 * (odd/tasks/mercados.md, odd/tasks/mercados-o5.md). Run twice a day by
 * `portal:sync-markets`; every series of the catalogue (MarketSeries) is
 * a source of its own.
 *
 * - TRM: the last validities of the official dataset of datos.gov.co,
 *   saved by (code, start date).
 * - Euro in pesos: the ECB's dollars per euro of its reference date D times
 *   the TRM in force ON D (the newest stored with `valid_from <= D`), saved
 *   on D. The ECB has no peso rate. The row of D is therefore always
 *   TRM(D) × ECB(D), however many times and whenever it runs: the ECB
 *   publishes at about 16:00 CET (09:00–10:00 in Bogotá), so the 07:10 run
 *   still sees the previous business day's file, and over a weekend or a
 *   TARGET holiday every run sees Friday's. Using today's TRM instead would
 *   rewrite the row of the previous day with a TRM that was not in force.
 * - Each source stands alone: a failure (HTTP error, redirect, a response
 *   over the limit, a timeout, a body that is not what it should be) keeps
 *   the last stored value, logs a short code (never the body) and the other
 *   source goes on. With the TRM down, the euro still follows the last
 *   stored one.
 * - Banco de la República (no key): the policy rate and COLCAP, from their
 *   `_HIST` SDMX dataflows (BanRepSdmx) asked from `startPeriod` on (the
 *   policy rate's flow ignores it today and answers since 2025, about
 *   130 KB). Only what the band needs is kept: for the policy rate today's
 *   value and the last day of the rate before the last decision (MarketBoard
 *   compares with it), for COLCAP the last two months.
 * - FRED (key `services.fred.key`): Brent and the input prices and indexes
 *   (FredObservations), the newest OBSERVATIONS of each series. Without a
 *   key they are skipped (`no_key`), which is not a failure. The key goes
 *   in the query string FRED requires and nowhere else: a failure logs
 *   only a short code, never an exception message or a URL.
 * - A monthly series is read once a day: a run skips it (`fresh`) when one
 *   of its rows was already fetched today in Bogotá, so the 13:10 run only
 *   retries what the 07:10 run could not get. Daily series (TRM, euro,
 *   Brent, the policy rate) are read every run.
 * - Every request goes over https to a fixed host and sends the User-Agent
 *   `PortalRYR/1.0` (FRED turns browsers away).
 * - The command runs inside `schedule:run` on shared hosting (no
 *   runInBackground there), one series after the other: each BanRep and
 *   FRED request gets SOURCE_CONNECT_TIMEOUT to connect and SOURCE_TIMEOUT
 *   in all, so a slow source cannot hold the scheduler for minutes.
 * - Requests never follow redirects and read at most `markets.max_bytes` of
 *   the body. `markets.timeout` is both the connection timeout and a
 *   wall-clock deadline for the whole answer: with `stream` Guzzle's stream
 *   handler drops its total timeout and keeps only the idle time between
 *   reads, so a source that trickles bytes would never end without it.
 */
final class MarketSync
{
    private const READ_CHUNK = 8192;

    /** Not a browser: FRED refuses browser User-Agents. */
    public const USER_AGENT = 'PortalRYR/1.0';

    /** SDMX REST data of a Banco de la República dataflow (`%s`). */
    public const BANREP_URL = 'https://totoro.banrep.gov.co/nsi-jax-ws/rest/data/ESTAT,%s,1.0/all/ALL/';

    public const FRED_URL = 'https://api.stlouisfed.org/fred/series/observations';

    /** How many of the newest observations of a FRED series are kept each run. */
    public const OBSERVATIONS = 10;

    /** Months of history asked for a rate that moves by decision (its last move may be a year old). */
    public const DECISION_MONTHS = 13;

    /** Months of history asked for the other BanRep series (COLCAP: the last two months). */
    public const HISTORY_MONTHS = 3;

    /** Seconds a BanRep or FRED request may take to connect, and in all. */
    public const SOURCE_CONNECT_TIMEOUT = 5;

    public const SOURCE_TIMEOUT = 8;

    /** A series id goes into a URL: only these characters. */
    private const SOURCE_KEY = '/\A[A-Z0-9_]{1,40}\z/';

    public function __construct(private readonly Http $http) {}

    public function run(): MarketSyncResult
    {
        $saved = [];
        $failed = [];
        $skipped = [];
        $fredKey = trim((string) config('services.fred.key'));
        $fresh = $this->monthlyFetchedToday();
        $jobs = [];

        foreach (MarketSeries::all() as $code => $series) {
            if ($series->source === MarketSeries::SOURCE_FRED && $fredKey === '') {
                $skipped[$code] = 'no_key';
            } elseif (in_array($code, $fresh, true)) {
                $skipped[$code] = 'fresh';
            } else {
                $jobs[$code] = match ($series->source) {
                    MarketSeries::SOURCE_TRM => fn () => $this->syncTrm($series),
                    MarketSeries::SOURCE_ECB => fn () => $this->syncEuro($series),
                    MarketSeries::SOURCE_BANREP => fn () => $this->syncBanRep($series),
                    MarketSeries::SOURCE_FRED => fn () => $this->syncFred($series, $fredKey),
                };
            }
        }

        foreach ($jobs as $code => $sync) {
            try {
                $saved[$code] = $sync();
            } catch (MarketSourceException $e) {
                $failed[$code] = $e->reason;
                Log::warning('Market rates could not be updated; the last value is kept.', ['source' => $code, 'code' => $e->reason]);
            } catch (Throwable $e) {
                // Our own code, the database: only the class, its message may carry a query.
                $failed[$code] = 'unexpected';
                Log::warning('Market rates could not be updated; the last value is kept.', ['source' => $code, 'code' => 'unexpected', 'exception' => $e::class]);
            }
        }

        return new MarketSyncResult($saved, $failed, $skipped);
    }

    /**
     * The monthly codes with a row fetched today in Bogotá (one query).
     *
     * @return list<string>
     */
    private function monthlyFetchedToday(): array
    {
        $monthly = array_keys(array_filter(MarketSeries::all(), fn (MarketSeries $series) => $series->isMonthly()));

        // `fetched_at` is written in the app's time zone.
        $startOfToday = now(MarketSeries::TZ)->startOfDay()->setTimezone((string) config('app.timezone'));

        return MarketRate::query()
            ->whereIn('code', $monthly)
            ->where('fetched_at', '>=', $startOfToday)
            ->distinct()
            ->pluck('code')
            ->all();
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncBanRep(MarketSeries $series): int
    {
        $months = $series->movesByDecision ? self::DECISION_MONTHS : self::HISTORY_MONTHS;

        $observations = BanRepSdmx::observations($this->fetch(
            sprintf(self::BANREP_URL, self::sourceKey($series)),
            ['startPeriod' => now(MarketSeries::TZ)->subMonthsNoOverflow($months)->format('Y-m')],
            short: true,
        ));

        return $this->store($series, self::needed($series, $observations));
    }

    /**
     * What the band needs of an answer that may carry hundreds of days: the
     * newest observation and the one it is compared with. For a rate that
     * moves by decision, the newest observation with another value (the
     * last day before the last decision), or nothing when it never changed;
     * for any other series, the one before the newest.
     *
     * @param  non-empty-list<array{date: string, value: string}>  $observations  Newest first.
     * @return list<array{date: string, value: string}>
     */
    private static function needed(MarketSeries $series, array $observations): array
    {
        $latest = $observations[0];

        if (! $series->movesByDecision) {
            return array_slice($observations, 0, 2);
        }

        foreach ($observations as $observation) {
            if ($observation['value'] !== $latest['value']) {
                return [$latest, $observation];
            }
        }

        return [$latest];
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncFred(MarketSeries $series, string $key): int
    {
        $observations = FredObservations::parse($this->fetch(self::FRED_URL, [
            'series_id' => self::sourceKey($series),
            'api_key' => $key,
            'file_type' => 'json',
            'sort_order' => 'desc',
            'limit' => self::OBSERVATIONS,
        ], short: true));

        return $this->store($series, $observations);
    }

    /**
     * @throws MarketSourceException `invalid_series` when the catalogue id could not go in a URL
     */
    private static function sourceKey(MarketSeries $series): string
    {
        if ($series->sourceKey === null || preg_match(self::SOURCE_KEY, $series->sourceKey) !== 1) {
            throw new MarketSourceException('invalid_series');
        }

        return $series->sourceKey;
    }

    /**
     * Saves observations by (code, date): a run that repeats a date updates its row.
     *
     * @param  list<array{date: string, value: string, valid_until?: string|null}>  $observations
     * @return int Rows written.
     */
    private function store(MarketSeries $series, array $observations): int
    {
        $now = now();

        $rows = array_map(fn (array $observation) => [
            'code' => $series->code,
            'value' => $observation['value'],
            'valid_from' => $observation['date'],
            'valid_until' => $observation['valid_until'] ?? null,
            'source' => $series->source,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $observations);

        MarketRate::query()->upsert($rows, ['code', 'valid_from'], ['value', 'valid_until', 'source', 'fetched_at', 'updated_at']);

        return count($rows);
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncTrm(MarketSeries $series): int
    {
        $body = $this->fetch((string) config('markets.trm_url'), [
            // The newest revision of a validity first: TrmDataset keeps the first of a repeated date.
            '$order' => 'vigenciadesde DESC, :updated_at DESC',
            '$limit' => (int) config('markets.trm_limit', 10),
        ]);

        return $this->store($series, array_map(fn (array $row) => [
            'date' => $row['valid_from'],
            'value' => $row['value'],
            'valid_until' => $row['valid_until'],
        ], TrmDataset::parse($body)));
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncEuro(MarketSeries $series): int
    {
        $ecb = EcbRates::usdPerEur($this->fetch((string) config('markets.ecb_url')));

        // The TRM in force on the ECB's date, as stored (this run's or an earlier one).
        $trm = MarketRate::query()
            ->where('code', MarketRate::TRM)
            ->where('valid_from', '<=', $ecb['date'])
            ->orderByDesc('valid_from')
            ->first();

        if ($trm === null) {
            throw new MarketSourceException('no_trm');
        }

        return $this->store($series, [[
            'date' => $ecb['date'],
            'value' => Decimal::multiply($trm->value, $ecb['rate'], 4),
        ]]);
    }

    /**
     * The body of a 200 answer of at most `markets.max_bytes` (1 MB: the
     * policy rate's history is about 130 KB).
     *
     * @param  array<string, mixed>  $query
     * @param  bool  $short  The time limits of BanRep and FRED (SOURCE_*), never over `markets.timeout`.
     *
     * @throws MarketSourceException
     */
    private function fetch(string $url, array $query = [], bool $short = false): string
    {
        $timeout = (int) config('markets.timeout', 10);
        $connectTimeout = $short ? min($timeout, self::SOURCE_CONNECT_TIMEOUT) : $timeout;
        $timeout = $short ? min($timeout, self::SOURCE_TIMEOUT) : $timeout;
        $max = (int) config('markets.max_bytes', 1024 * 1024);
        $deadline = microtime(true) + $timeout;

        try {
            // `stream`: the body is read below, a piece at a time, so a huge one is never downloaded whole.
            $response = $this->http
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withoutRedirecting()
                ->withUserAgent(self::USER_AGENT)
                ->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->get($url, $query);
        } catch (Throwable) {
            // A ConnectionException or anything else of the request: its
            // message may carry the URL, and the URL of FRED carries the key.
            throw new MarketSourceException('connection');
        }

        $stream = $response->toPsrResponse()->getBody();

        // Every way out closes the stream, also the early ones.
        try {
            if ($response->status() !== 200) {
                // Includes 3xx: a redirect is never followed.
                throw new MarketSourceException('http_'.$response->status());
            }

            $length = $response->header('Content-Length');

            if (is_numeric($length) && (int) $length > $max) {
                throw new MarketSourceException('too_large');
            }

            $body = '';

            while (! $stream->eof()) {
                $chunk = $stream->read(self::READ_CHUNK);

                if ($chunk === '') {
                    // A body that ends on a chunk boundary only shows its end now.
                    if ($stream->eof()) {
                        break;
                    }

                    // The source went quiet before the end.
                    throw new MarketSourceException('connection');
                }

                $body .= $chunk;

                if (strlen($body) > $max) {
                    throw new MarketSourceException('too_large');
                }

                // The idle timeout of each read does not bound a trickle.
                if (! $stream->eof() && microtime(true) > $deadline) {
                    throw new MarketSourceException('timeout');
                }
            }

            return $body;
        } catch (MarketSourceException $e) {
            throw $e;
        } catch (Throwable) {
            throw new MarketSourceException('connection');
        } finally {
            $stream->close();
        }
    }
}
