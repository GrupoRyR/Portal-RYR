<?php

namespace Tests\Feature\Markets\Concerns;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake answers of the Banco de la República (SDMX) and FRED for
 * `portal:sync-markets`, from tests/Fixtures/markets: the BanRep ones are
 * copies of the live `_HIST` answers of 2026-11-04 (the policy rate since
 * 2025-01-01, the policy rate's flow ignores `startPeriod`; COLCAP since
 * 2026-01), the FRED ones hand-made in the shape of its documentation.
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
            $answer = str_contains($request->url(), 'DF_CBR_DAILY') ? $tpm : $colcap;

            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return $answer ?? Http::response(
                $this->marketFixture(str_contains($request->url(), 'DF_CBR_DAILY') ? 'banrep-cbr-hist.xml' : 'banrep-colcap-hist.xml'),
                200,
                ['Content-Type' => 'application/xml'],
            );
        };
    }

    /**
     * A policy-rate answer in the live shape with these observations.
     *
     * @param  array<string, string>  $observations  Value by day («20261104»), oldest first.
     */
    protected function policyRateXml(array $observations): string
    {
        $obs = '';

        foreach ($observations as $day => $value) {
            $obs .= "<generic:Obs><generic:ObsDimension value=\"{$day}\" /><generic:ObsValue value=\"{$value}\" />"
                .'<generic:Attributes><generic:Value id="OBS_STATUS" value="A" /></generic:Attributes></generic:Obs>';
        }

        return "<?xml version='1.0' encoding='UTF-8'?>"
            .'<message:GenericData xmlns:generic="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/data/generic" xmlns:message="http://www.sdmx.org/resources/sdmxml/schemas/v2_1/message">'
            .'<message:Header><message:ID>DF_CBR_DAILY_HIST</message:ID></message:Header><message:DataSet structureRef="OECD_STES_3_0"><generic:Series>'
            .'<generic:SeriesKey><generic:Value id="SUBJECT" value="IRCBRM01" /><generic:Value id="FREQ" value="D" /></generic:SeriesKey>'
            ."{$obs}</generic:Series></message:DataSet></message:GenericData>";
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
