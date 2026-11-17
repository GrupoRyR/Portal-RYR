<?php

namespace App\Management;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Actualizar ventas y cartera» of the «Panel gerencial» on «Bases de datos
 * SAP» (odd/tasks/panel-gerencial-sap.md, T3; that repository's
 * docs/guia-apps.md §2 and docs/contrato-robot.md §7.4). Everything runs
 * on the Portal's server with its own key (config services.bases_sap):
 * the key never reaches the browser.
 *
 * - request(): asks for VENTAS and CARTERA of one company (`parte` null),
 *   on behalf of the person, and keeps both request ids per company.
 *   201 (created) and 200 (joined to one already waiting) both succeed;
 *   the first refusal stops and gives a plain SapRefreshOutcome, and a
 *   half-accepted press is not followed (only a complete pair is).
 * - status(): the message of guide §2.4 for those requests. Each request's
 *   state is asked for at most once per STATUS_CACHE_SECONDS, whoever is
 *   looking (the key has 60 requests a minute for every viewer). When both
 *   have ended, the company's cached figures (App\Management\SapFigures)
 *   and last update are dropped, once, so the page reads the views again,
 *   and the final message stays for FINISHED_CACHE_SECONDS.
 * - updatedAt(): the older of the last updates of VENTAS and CARTERA
 *   (`GET /api/v1/estado`), for «Datos actualizados a las …»; null when
 *   unknown. Cached for FRESHNESS_CACHE_SECONDS, failures too.
 *
 * Reads made while the home page renders use a short READ_TIMEOUT and go
 * in pairs at once (Http::pool): a stopped API never holds the page for
 * more than one timeout per pair. Nothing here throws: a network
 * failure is expected (logged on a press), anything else is reported.
 */
final class SapRefresh
{
    /** The sets the button asks for, in this order. */
    public const DATASETS = ['VENTAS', 'CARTERA'];

    public const REQUEST_TIMEOUT = 10;

    public const READ_TIMEOUT = 3;

    public const STATUS_CACHE_SECONDS = 30;

    public const FRESHNESS_CACHE_SECONDS = 300;

    /** How long a press is followed; past it the panel forgets the requests. */
    public const PENDING_CACHE_SECONDS = 3600;

    public const FINISHED_CACHE_SECONDS = 600;

    /** The messages of the guide §2.4. */
    public const PENDING = 'Pedimos los datos a SAP. Suele tardar unos minutos.';

    public const QUEUED_AT = 'Se actualizará a las %s.';

    public const QUEUED = 'Hay otra actualización en curso; esta sigue después.';

    public const TAKEN = 'Actualizando datos…';

    public const DONE = 'Datos actualizados.';

    public const FAILED = 'No se pudo actualizar. Inténtalo de nuevo más tarde.';

    public const EXPIRED = 'La actualización tardó demasiado. Inténtalo de nuevo.';

    private const STATES = ['pendiente', 'en_cola', 'tomada', 'terminada', 'fallida', 'vencida'];

    private const ENDED = ['terminada', 'fallida', 'vencida'];

    /** A read that failed, cached so a broken API is not asked on every page. */
    private const UNKNOWN = 'unknown';

    public function __construct(private readonly SapFigures $figures) {}

    /** Whether the API URL and the Portal's key are set. */
    public function configured(): bool
    {
        return trim((string) config('services.bases_sap.url')) !== ''
            && trim((string) config('services.bases_sap.key')) !== '';
    }

    /**
     * Asks for fresh VENTAS and CARTERA of the company with this `empresa`
     * code, on behalf of the person.
     */
    public function request(User $user, string $company): SapRefreshOutcome
    {
        $ids = [];
        $outcome = SapRefreshOutcome::Requested;

        foreach (self::DATASETS as $dataset) {
            [$outcome, $id] = $this->place($user, $company, $dataset);

            if ($id === null) {
                break;
            }

            $ids[$dataset] = $id;
        }

        // Only a complete pair is followed: half of one would later say
        // «Datos actualizados.» with one set old. An earlier press is
        // forgotten either way, so its message does not linger.
        if (count($ids) === count(self::DATASETS)) {
            Cache::put(self::requestsKey($company), ['ids' => $ids], self::PENDING_CACHE_SECONDS);
        } else {
            Cache::forget(self::requestsKey($company));
        }

        return $outcome;
    }

    /**
     * The message about the company's last press, or null when there is
     * nothing to say (no press, or its state could not be read).
     */
    public function status(string $company, ?CarbonInterface $now = null): ?string
    {
        $record = Cache::get(self::requestsKey($company));

        if (! is_array($record)) {
            return null;
        }

        if (is_string($record['message'] ?? null)) {
            return $record['message'];
        }

        $states = $this->states($record['ids'] ?? []);
        $known = array_values(array_filter($states));

        if ($known === []) {
            return null;
        }

        $names = array_column($known, 'estado');

        if (count($known) === count($states) && array_diff($names, self::ENDED) === []) {
            return $this->finish($company, $names, $now);
        }

        if (in_array('tomada', $names, true)) {
            return self::TAKEN;
        }

        if (in_array('en_cola', $names, true)) {
            $times = array_filter(array_map(
                fn (array $state) => $state['estado'] === 'en_cola' ? self::instant($state['correr_desde']) : null,
                $known,
            ));

            return $times === []
                ? self::QUEUED
                : sprintf(self::QUEUED_AT, max($times)->setTimezone(SapFigures::TIMEZONE)->format('H:i'));
        }

        return in_array('pendiente', $names, true) ? self::PENDING : null;
    }

    /**
     * The older of the last times VENTAS and CARTERA of the company were
     * applied (nightly run or requested), or null when either is unknown.
     */
    public function updatedAt(string $company): ?CarbonImmutable
    {
        $key = self::freshnessKey($company);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === self::UNKNOWN ? null : CarbonImmutable::parse($cached);
        }

        // Both sets at once: a slow API costs one READ_TIMEOUT, not two.
        $responses = $this->getAll(array_combine(self::DATASETS, array_map(
            fn (string $dataset) => ['/api/v1/estado', ['empresa' => $company, 'conjunto' => $dataset]],
            self::DATASETS,
        )));
        $oldest = null;

        foreach (self::DATASETS as $dataset) {
            $time = self::lastUpdate($responses[$dataset] ?? null);

            if ($time === null) {
                Cache::put($key, self::UNKNOWN, self::FRESHNESS_CACHE_SECONDS);

                return null;
            }

            $oldest = $oldest === null || $time->lessThan($oldest) ? $time : $oldest;
        }

        Cache::put($key, $oldest->toIso8601String(), self::FRESHNESS_CACHE_SECONDS);

        return $oldest;
    }

    public static function requestsKey(string $company): string
    {
        return "management:sap-refresh:{$company}";
    }

    public static function freshnessKey(string $company): string
    {
        return "management:sap-freshness:{$company}";
    }

    /**
     * @return array{0: SapRefreshOutcome, 1: int|null} The outcome and the request id.
     */
    private function place(User $user, string $company, string $dataset): array
    {
        try {
            $response = $this->client(self::REQUEST_TIMEOUT)->post('/api/v1/solicitudes', [
                'empresa' => $company,
                'conjunto' => $dataset,
                'parte' => null,
                'solicitado_por' => ['correo' => $user->email, 'nombre' => $user->name],
            ]);
        } catch (ConnectionException) {
            Log::warning('Bases SAP could not be reached to request an update.', ['empresa' => $company, 'conjunto' => $dataset]);

            return [SapRefreshOutcome::Unreachable, null];
        } catch (Throwable $exception) {
            report($exception);

            return [SapRefreshOutcome::Unreachable, null];
        }

        $id = self::json($response, 'id');

        if (in_array($response->status(), [200, 201], true) && is_int($id)) {
            return [SapRefreshOutcome::Requested, $id];
        }

        // The status only: the body may echo what was sent.
        Log::warning('Bases SAP did not accept an update request.', ['empresa' => $company, 'conjunto' => $dataset, 'status' => $response->status()]);

        return [match (true) {
            $response->status() === 429 => SapRefreshOutcome::TooManyRequests,
            $response->serverError(), $response->successful() => SapRefreshOutcome::Unreachable,
            default => SapRefreshOutcome::Refused,
        }, null];
    }

    /**
     * Both requests ended: drops the cached figures and last update when
     * one of them brought data, and keeps the final message instead of the
     * ids, so this happens once.
     *
     * @param  list<string>  $names
     */
    private function finish(string $company, array $names, ?CarbonInterface $now): string
    {
        if (in_array('terminada', $names, true)) {
            $this->figures->forget($company, $now);
            Cache::forget(self::freshnessKey($company));
        }

        $message = match (true) {
            in_array('fallida', $names, true) => self::FAILED,
            in_array('vencida', $names, true) => self::EXPIRED,
            default => self::DONE,
        };

        Cache::put(self::requestsKey($company), ['message' => $message], self::FINISHED_CACHE_SECONDS);

        return $message;
    }

    /**
     * The state of each request, cached for STATUS_CACHE_SECONDS whatever
     * the answer; null for one that could not be read. The ones not cached
     * are asked at once (getAll()).
     *
     * @param  array<string, int>  $ids  Keyed by set.
     * @return array<string, array{estado: string, correr_desde: string|null}|null>
     */
    private function states(array $ids): array
    {
        $states = [];
        $missing = [];

        foreach ($ids as $dataset => $id) {
            $cached = Cache::get(self::stateKey($id));

            if (is_array($cached) || $cached === self::UNKNOWN) {
                $states[$dataset] = is_array($cached) ? $cached : null;
            } else {
                $missing[$dataset] = $id;
            }
        }

        $responses = $this->getAll(array_map(fn (int $id) => ["/api/v1/solicitudes/{$id}", []], $missing));

        foreach ($missing as $dataset => $id) {
            $state = self::state($responses[$dataset] ?? null);
            Cache::put(self::stateKey($id), $state ?? self::UNKNOWN, self::STATUS_CACHE_SECONDS);
            $states[$dataset] = $state;
        }

        return $states;
    }

    private static function stateKey(int $id): string
    {
        return "management:sap-request:{$id}";
    }

    /**
     * @return array{estado: string, correr_desde: string|null}|null
     */
    private static function state(?Response $response): ?array
    {
        $estado = $response?->successful() ? self::json($response, 'estado') : null;
        $runFrom = $response === null ? null : self::json($response, 'correr_desde');

        return is_string($estado) && in_array($estado, self::STATES, true)
            ? ['estado' => $estado, 'correr_desde' => is_string($runFrom) ? $runFrom : null]
            : null;
    }

    /** The oldest `actualizado_en` of the set's parts, or null when any is missing. */
    private static function lastUpdate(?Response $response): ?CarbonImmutable
    {
        $parts = $response?->successful() ? self::json($response, 'partes') : null;

        if (! is_array($parts) || $parts === []) {
            return null;
        }

        $oldest = null;

        foreach ($parts as $part) {
            $time = self::instant(is_array($part) ? ($part['actualizado_en'] ?? null) : null);

            if ($time === null) {
                return null;
            }

            $oldest = $oldest === null || $time->lessThan($oldest) ? $time : $oldest;
        }

        return $oldest;
    }

    /**
     * Several GETs at once (Http::pool), so a slow API holds the page for
     * one READ_TIMEOUT, not one per call. A network failure gives null; any
     * other failure is reported and gives null too.
     *
     * @param  array<string, array{0: string, 1: array<string, string>}>  $gets  Path and query, keyed by name.
     * @return array<string, Response|null>
     */
    private function getAll(array $gets): array
    {
        if ($gets === []) {
            return [];
        }

        try {
            $results = Http::pool(function (Pool $pool) use ($gets) {
                foreach ($gets as $name => [$path, $query]) {
                    $this->configure($pool->as((string) $name), self::READ_TIMEOUT)->get($path, $query);
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return array_fill_keys(array_keys($gets), null);
        }

        $responses = [];

        foreach (array_keys($gets) as $name) {
            $result = $results[$name] ?? null;

            if ($result instanceof Throwable && ! $result instanceof ConnectionException) {
                report($result);
            }

            $responses[$name] = $result instanceof Response ? $result : null;
        }

        return $responses;
    }

    private function client(int $timeout): PendingRequest
    {
        return $this->configure(Http::withOptions([]), $timeout);
    }

    /** The API's base URL, the Portal's key and the timeouts, for one call or a pooled one. */
    private function configure(PendingRequest $request, int $timeout): PendingRequest
    {
        return $request->baseUrl((string) config('services.bases_sap.url'))
            ->withToken((string) config('services.bases_sap.key'))
            ->acceptJson()
            ->connectTimeout(min($timeout, 5))
            ->timeout($timeout);
    }

    /** A key of the JSON body; null when the body is not JSON. */
    private static function json(Response $response, string $key): mixed
    {
        $body = json_decode($response->body(), true);

        return is_array($body) ? ($body[$key] ?? null) : null;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
