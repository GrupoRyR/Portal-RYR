<?php

namespace App\Markets;

use App\Models\Company;
use App\Models\MarketRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Read model of the markets band: for each code of the catalogue
 * (App\Markets\MarketSeries) the latest value, its validity and the change
 * against the previous one, absolute and in percent, in ONE query.
 *
 * «Latest» is the newest value that already started today in Bogotá: the
 * dataset publishes the TRM ahead of its validity, and a figure must not
 * show before its day. A code that has no value yet is left out.
 *
 * A series that moves by decision (the policy rate, MarketSeries) is
 * compared with its last DIFFERENT stored value, in the same query: the
 * band shows the last decision with the current value's date. One that
 * never changed shows no move.
 *
 * A company sees the series for everyone, then the inputs it chose
 * (`companies.market_codes`, read from the company already loaded: no query
 * of its own). Without a company, or without a choice, only the series for
 * everyone.
 */
final class MarketBoard
{
    private const TZ = 'America/Bogota';

    /**
     * @return array<string, MarketQuote> By code, in the order of the catalogue (MarketSeries).
     */
    public function current(?Company $company = null): array
    {
        $codes = MarketSeries::codesFor($company?->marketCodes() ?? []);

        $byDecision = array_values(array_filter($codes, fn (string $code) => MarketSeries::find($code)?->movesByDecision === true));

        // Window functions: MariaDB 10.2+ / MySQL 8 (production) and SQLite
        // have them. Every value that already started, ranked newest first,
        // with the newest value of its code beside it.
        $ranked = MarketRate::query()
            ->select('*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY code ORDER BY valid_from DESC) AS position')
            ->selectRaw('FIRST_VALUE(value) OVER (PARTITION BY code ORDER BY valid_from DESC) AS latest_value')
            ->whereIn('code', $codes)
            ->where('valid_from', '<=', Carbon::now(self::TZ)->toDateString());

        // The newest value, then the ones a change may be measured against:
        // any for most codes, only a different one for a decided rate.
        $kept = MarketRate::query()
            ->fromSub($ranked, 'ranked')
            ->select('ranked.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY code ORDER BY valid_from DESC) AS kept_position')
            ->when($byDecision !== [], fn ($query) => $query->where(fn ($query) => $query
                ->where('position', 1)
                ->orWhereNotIn('code', $byDecision)
                ->orWhereColumn('value', '<>', 'latest_value')));

        // The two first of each code: the newest and the one it moved from.
        $byCode = MarketRate::query()
            ->fromSub($kept, 'kept')
            ->where('kept_position', '<=', 2)
            ->orderBy('code')
            ->orderBy('kept_position')
            ->get()
            ->groupBy('code');

        $board = [];

        foreach ($codes as $code) {
            $rows = $byCode->get($code);

            if ($rows !== null) {
                $board[$code] = $this->quote($code, $rows->first(), $rows->get(1));
            }
        }

        return $board;
    }

    private function quote(string $code, MarketRate $latest, ?MarketRate $previous): MarketQuote
    {
        $value = (float) $latest->value;
        // A decided rate that never changed: no move, rather than no comparison.
        $previous ??= MarketSeries::find($code)?->movesByDecision === true ? $latest : null;
        $before = $previous === null ? null : (float) $previous->value;
        $change = $before === null ? null : round($value - $before, 4);

        return new MarketQuote(
            code: $code,
            value: $value,
            validFrom: CarbonImmutable::instance($latest->valid_from),
            validUntil: $latest->valid_until === null ? null : CarbonImmutable::instance($latest->valid_until),
            change: $change,
            changePercent: $change === null || $before <= 0 ? null : round($change / $before * 100, 2),
        );
    }
}
