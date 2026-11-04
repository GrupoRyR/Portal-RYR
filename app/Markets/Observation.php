<?php

namespace App\Markets;

/**
 * Checks one observation of a source before it is believed: a plain
 * non-negative decimal (no exponent, no comma, no «NaN») on a real date.
 * Shared by App\Markets\BanRepSdmx and App\Markets\FredObservations.
 */
final class Observation
{
    /** What `market_rates.value` (decimal 14,4) holds: up to ten integer digits. */
    private const AMOUNT = '/\A\d{1,10}(\.\d{1,12})?\z/';

    /** «2026-11-04», or the compact «20261104» of the BanRep daily dataflows. */
    private const DAY = '/\A(\d{4})-?(\d{2})-?(\d{2})\z/';

    /** «2026-09» or the SDMX «2026-M09». */
    private const MONTH = '/\A(\d{4})-M?(\d{2})\z/';

    /**
     * The amount with four decimals («9.2500»), or null when it is not a
     * plain non-negative decimal string.
     */
    public static function amount(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::AMOUNT, $value) !== 1) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    /**
     * The `Y-m-d` of a day («2026-11-03», «20261103») or of the first day of
     * a month («2026-09», «2026-M09»), or null when it is not a real date.
     */
    public static function date(mixed $value, bool $monthsToo = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Both dashes or neither: «2026-1104» is no date.
        if (preg_match(self::DAY, $value, $m) === 1 && in_array(substr_count($value, '-'), [0, 2], true)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        if ($monthsToo && preg_match(self::MONTH, $value, $m) === 1 && checkdate((int) $m[2], 1, (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-01";
        }

        return null;
    }
}
