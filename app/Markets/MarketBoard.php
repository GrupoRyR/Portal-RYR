<?php

namespace App\Markets;

use App\Models\MarketRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Read model of the markets band: for each code (TRM, euro) the latest
 * value, its validity and the change against the previous one, absolute
 * and in percent, in ONE query.
 *
 * «Latest» is the newest value that already started today in Bogotá: the
 * dataset publishes the TRM ahead of its validity, and a figure must not
 * show before its day. A code that has no value yet is left out.
 */
final class MarketBoard
{
    private const TZ = 'America/Bogota';

    /**
     * @return array<string, MarketQuote> By code, in the order of MarketRate::CODES.
     */
    public function current(): array
    {
        // The two newest values of each code that already started. Window
        // function: MySQL 8 (the production database) and SQLite have it.
        $ranked = MarketRate::query()
            ->select('*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY code ORDER BY valid_from DESC) AS position')
            ->whereIn('code', MarketRate::CODES)
            ->where('valid_from', '<=', Carbon::now(self::TZ)->toDateString());

        $byCode = MarketRate::query()
            ->fromSub($ranked, 'ranked')
            ->where('position', '<=', 2)
            ->orderBy('code')
            ->orderBy('position')
            ->get()
            ->groupBy('code');

        $board = [];

        foreach (MarketRate::CODES as $code) {
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
