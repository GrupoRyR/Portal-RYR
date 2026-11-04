<?php

namespace Tests\Feature\Markets\Concerns;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake answers of the Banco de la República (SDMX) and FRED for
 * `portal:sync-markets`, from tests/Fixtures/markets: the BanRep ones are
 * copies of the live answers of 2026-11-04 (one observation each), the
 * FRED ones hand-made in the shape of its documentation.
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
                $this->marketFixture(str_contains($request->url(), 'DF_CBR_DAILY_LATEST') ? 'banrep-cbr-live.xml' : 'banrep-colcap-live.xml'),
                200,
                ['Content-Type' => 'application/xml'],
            );
        };
    }

    /**
     * The live policy-rate answer with another day (`20261104`) and value.
     */
    protected function policyRateXml(string $day, string $value): string
    {
        return str_replace(
            ['<generic:ObsDimension value="20261104" />', '<generic:ObsValue value="12.25" />'],
            ["<generic:ObsDimension value=\"{$day}\" />", "<generic:ObsValue value=\"{$value}\" />"],
            $this->marketFixture('banrep-cbr-live.xml'),
        );
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
