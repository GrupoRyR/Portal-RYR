<?php

namespace App\Markets;

use App\Models\MarketRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies the market figures of the home band into `market_rates`
 * (odd/tasks/mercados.md). Run twice a day by `portal:sync-markets`.
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
 * - Requests never follow redirects and read at most `markets.max_bytes` of
 *   the body. `markets.timeout` is both the connection timeout and a
 *   wall-clock deadline for the whole answer: with `stream` Guzzle's stream
 *   handler drops its total timeout and keeps only the idle time between
 *   reads, so a source that trickles bytes would never end without it.
 */
final class MarketSync
{
    private const READ_CHUNK = 8192;

    public function __construct(private readonly Http $http) {}

    public function run(): MarketSyncResult
    {
        $saved = [];
        $failed = [];

        foreach ([MarketRate::TRM => $this->syncTrm(...), MarketRate::EUR => $this->syncEuro(...)] as $code => $sync) {
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

        return new MarketSyncResult($saved, $failed);
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncTrm(): int
    {
        $body = $this->fetch((string) config('markets.trm_url'), [
            // The newest revision of a validity first: TrmDataset keeps the first of a repeated date.
            '$order' => 'vigenciadesde DESC, :updated_at DESC',
            '$limit' => (int) config('markets.trm_limit', 10),
        ]);
        $now = now();

        $rows = array_map(fn (array $row) => [
            'code' => MarketRate::TRM,
            'value' => $row['value'],
            'valid_from' => $row['valid_from'],
            'valid_until' => $row['valid_until'],
            'source' => 'datos.gov.co',
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], TrmDataset::parse($body));

        MarketRate::query()->upsert($rows, ['code', 'valid_from'], ['value', 'valid_until', 'source', 'fetched_at', 'updated_at']);

        return count($rows);
    }

    /**
     * @return int Rows written.
     *
     * @throws MarketSourceException
     */
    private function syncEuro(): int
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

        $now = now();

        MarketRate::query()->upsert([[
            'code' => MarketRate::EUR,
            'value' => Decimal::multiply($trm->value, $ecb['rate'], 4),
            'valid_from' => $ecb['date'],
            'valid_until' => null,
            'source' => 'ecb.europa.eu',
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['code', 'valid_from'], ['value', 'valid_until', 'source', 'fetched_at', 'updated_at']);

        return 1;
    }

    /**
     * The body of a 200 answer of at most `markets.max_bytes`.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws MarketSourceException
     */
    private function fetch(string $url, array $query = []): string
    {
        $timeout = (int) config('markets.timeout', 10);
        $max = (int) config('markets.max_bytes', 1024 * 1024);
        $deadline = microtime(true) + $timeout;

        try {
            // `stream`: the body is read below, a piece at a time, so a huge one is never downloaded whole.
            $response = $this->http
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->get($url, $query);
        } catch (ConnectionException) {
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
