<?php

namespace Tests\Feature\Markets\Concerns;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake answers of the Banco de la República (SDMX) and FRED for
 * `portal:sync-markets`, from the hand-made samples in tests/Fixtures/markets.
 * Each answer is built per request: a read body is not read twice.
 */
trait FakesMarketSources
{
    protected function marketFixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/markets/'.$name));
    }

    /**
     * Answers both BanRep dataflows; pass a response (or a closure) to change one.
     */
    protected function banrepResponder(mixed $tpm = null, mixed $colcap = null): Closure
    {
        return function (Request $request) use ($tpm, $colcap) {
            $answer = str_contains($request->url(), 'DF_CBR_DAILY_LATEST') ? $tpm : $colcap;

            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return $answer ?? Http::response(
                $this->marketFixture(str_contains($request->url(), 'DF_CBR_DAILY_LATEST') ? 'banrep-tpm-daily.xml' : 'banrep-colcap-monthly.xml'),
                200,
                ['Content-Type' => 'application/xml'],
            );
        };
    }

    /**
     * Answers every FRED series with the Brent sample (daily) or the zinc
     * sample (monthly); `$bySeries` changes the answer of a series id.
     *
     * @param  array<string, mixed>  $bySeries
     */
    protected function fredResponder(array $bySeries = []): Closure
    {
        return function (Request $request) use ($bySeries) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $series = (string) ($query['series_id'] ?? '');
            $answer = $bySeries[$series] ?? null;

            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return $answer ?? Http::response(
                $this->marketFixture($series === 'DCOILBRENTEU' ? 'fred-brent-daily.json' : 'fred-zinc-monthly.json'),
                200,
                ['Content-Type' => 'application/json'],
            );
        };
    }
}
