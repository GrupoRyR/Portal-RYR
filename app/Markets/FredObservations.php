<?php

namespace App\Markets;

/**
 * Reads the observations of a FRED `series/observations` JSON answer:
 * `{"observations": [{"date": "2026-10-27", "value": "64.53"}, …]}`. A
 * monthly series is dated on the first day of its month. FRED marks a
 * period without a value with «.»: it is skipped, like anything that is not
 * a plain number on a real date.
 *
 * @see https://fred.stlouisfed.org/docs/api/fred/series_observations.html
 */
final class FredObservations
{
    /**
     * @return non-empty-list<array{date: string, value: string}> In the order of the answer, one per date.
     *
     * @throws MarketSourceException `invalid_json` or `invalid_data`
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true, 8);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MarketSourceException('invalid_json');
        }

        $observations = is_array($data) ? ($data['observations'] ?? null) : null;

        if (! is_array($observations) || ! array_is_list($observations)) {
            throw new MarketSourceException('invalid_data');
        }

        $rows = [];

        foreach ($observations as $observation) {
            $date = Observation::date(is_array($observation) ? ($observation['date'] ?? null) : null);
            $value = Observation::amount(is_array($observation) ? ($observation['value'] ?? null) : null);

            // The same date twice: the first one stays.
            if ($date !== null && $value !== null && ! isset($rows[$date])) {
                $rows[$date] = ['date' => $date, 'value' => $value];
            }
        }

        if ($rows === []) {
            throw new MarketSourceException('invalid_data');
        }

        return array_values($rows);
    }
}
