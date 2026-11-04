<?php

namespace App\Markets;

use App\Models\Company;
use App\Models\MarketInput;
use App\Models\MarketRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model of the markets band: for each code of the catalogue
 * (App\Markets\MarketSeries) the latest value, its validity and the change
 * against the previous one, absolute and in percent, in ONE query.
 *
 * «Latest» is the newest value that already started today in Bogotá: the
 * dataset publishes the TRM ahead of its validity, and a figure must not
 * show before its day. A code that has no value yet is left out, and rows
 * older than WINDOW_DAYS are not read.
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
 *
 * A company also gets the prices its admins typed by hand (`market_inputs`,
 * App\Models\MarketInput) in the SAME query (UNION ALL): after the series,
 * by name, the latest price of each name compared with the previous price
 * of that name when both are in the same unit. Two prices of one day: the
 * last one entered is the latest. Their code is «input-<id>».
 *
 * Names are one name without case or accents (MarketInput::fold()): MariaDB
 * already partitions them so, SQLite does not, and the board folds them
 * again. At most MANUAL_LIMIT names show: those whose latest price was
 * entered or changed most recently, still by name.
 */
final class MarketBoard
{
    /**
     * Only rows this recent are read, so the query stays small however long
     * the table grows: a year of daily values and a last decision of the
     * policy rate up to about thirteen months old.
     */
    public const WINDOW_DAYS = 400;

    /** Manual prices a company's band shows at most (one per name). */
    public const MANUAL_LIMIT = 12;

    /** `kind` of a row of the catalogue (`market_rates`). */
    private const RATE = 'rate';

    /** `kind` of a price typed by hand (`market_inputs`). */
    private const INPUT = 'input';

    /**
     * @return array<string, MarketQuote> By code: the catalogue's in its order (MarketSeries), then the company's manual prices by name.
     */
    public function current(?Company $company = null): array
    {
        $codes = MarketSeries::codesFor($company?->marketCodes() ?? []);

        $today = CarbonImmutable::now(MarketSeries::TZ)->startOfDay()->toDateString();
        $from = CarbonImmutable::now(MarketSeries::TZ)->startOfDay()->subDays(self::WINDOW_DAYS)->toDateString();
        $byDecision = array_values(array_filter($codes, fn (string $code) => MarketSeries::find($code)?->movesByDecision === true));

        // The rows of both tables in one shape: a manual price's name is
        // its code, its day its start, and `kind` keeps the two apart.
        $rows = DB::table('market_rates')
            ->selectRaw("id, code, value, valid_from, valid_until, updated_at, '".self::RATE."' AS kind, NULL AS unit")
            ->whereIn('code', $codes)
            ->where('valid_from', '<=', $today)
            ->where('valid_from', '>=', $from)
            ->when($company !== null, fn ($query) => $query->unionAll(DB::table('market_inputs')
                ->selectRaw("id, label AS code, value, valid_on AS valid_from, NULL AS valid_until, updated_at, '".self::INPUT."' AS kind, unit")
                ->where('company_id', $company->getKey())
                ->where('valid_on', '<=', $today)
                ->where('valid_on', '>=', $from)));

        // Window functions: MariaDB 10.2+ / MySQL 8 (production) and SQLite
        // have them. Every value that already started, ranked newest first
        // (the last entered first on a tie), with the newest value of its
        // code beside it.
        $order = 'PARTITION BY kind, code ORDER BY valid_from DESC, id DESC';
        $ranked = DB::query()
            ->fromSub($rows, 'all_rows')
            ->select('*')
            ->selectRaw("ROW_NUMBER() OVER ({$order}) AS position")
            ->selectRaw("FIRST_VALUE(value) OVER ({$order}) AS latest_value");

        // The newest value, then the ones a change may be measured against:
        // any for most codes, only a different one for a decided rate.
        $kept = DB::query()
            ->fromSub($ranked, 'ranked')
            ->select('ranked.*')
            ->selectRaw("ROW_NUMBER() OVER ({$order}) AS kept_position")
            ->when($byDecision !== [], fn ($query) => $query->where(fn ($query) => $query
                ->where('position', 1)
                ->orWhereRaw("kind = '".self::INPUT."'")
                ->orWhereNotIn('code', $byDecision)
                ->orWhereColumn('value', '<>', 'latest_value')));

        // The two first of each code: the newest and the one it moved from.
        $all = MarketRate::query()
            ->fromSub($kept, 'kept')
            ->where('kept_position', '<=', 2)
            ->orderBy('kind')
            ->orderBy('code')
            ->orderBy('kept_position')
            ->get();

        $byCode = $all->where('kind', self::RATE)->groupBy('code');
        $board = [];

        foreach ($codes as $code) {
            $rows = $byCode->get($code);

            if ($rows !== null) {
                $board[$code] = $this->quote($code, $rows->first(), $rows->get(1));
            }
        }

        return $board + $this->manualQuotes($all->where('kind', self::INPUT));
    }

    /**
     * The latest manual price of each name, by name, compared with the
     * previous one only in the same unit. Each spelling brings its two
     * newest rows, so the two newest of a folded name are among them.
     *
     * @param  Collection<int, MarketRate>  $rows
     * @return array<string, MarketQuote>
     */
    private function manualQuotes(Collection $rows): array
    {
        $byName = $rows
            ->groupBy(fn (MarketRate $row) => MarketInput::fold((string) $row->code))
            ->map(fn (Collection $rows) => $rows
                ->sortBy([
                    fn (MarketRate $a, MarketRate $b) => $b->valid_from <=> $a->valid_from,
                    fn (MarketRate $a, MarketRate $b) => $b->id <=> $a->id,
                ])
                ->values())
            // The names changed most recently, then by name.
            ->sortByDesc(fn (Collection $rows) => [$rows->first()->updated_at?->getTimestamp() ?? 0, $rows->first()->id])
            ->take(self::MANUAL_LIMIT)
            ->sortKeys(SORT_NATURAL);

        $quotes = [];

        foreach ($byName as $rows) {
            $latest = $rows->first();
            $name = (string) $latest->code;
            $unit = MarketUnit::tryFrom((string) $latest->getAttribute('unit'));
            $previous = $rows->get(1);

            if ($unit === null) {
                continue;
            }

            // Pesos per kilo against dollars per kilo is no change.
            if ($previous !== null && $previous->getAttribute('unit') !== $latest->getAttribute('unit')) {
                $previous = null;
            }

            // Its own code: a name such as «tpm» is not the policy rate.
            $code = 'input-'.$latest->id;
            $quote = $this->quote($code, $latest, $previous);

            $quotes[$code] = new MarketQuote(
                code: $code,
                value: $quote->value,
                validFrom: $quote->validFrom,
                validUntil: null,
                change: $quote->change,
                changePercent: $quote->changePercent,
                label: (string) $name,
                unit: $unit,
                manual: true,
            );
        }

        return $quotes;
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
