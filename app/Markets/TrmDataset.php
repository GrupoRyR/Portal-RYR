<?php

namespace App\Markets;

/**
 * Reads the TRM rows of the official dataset (datos.gov.co, 32sa-8pi3):
 * `valor` (pesos per dollar), `unidad`, `vigenciadesde` and `vigenciahasta`
 * (dates with a midnight time). Anything that is not a positive number of
 * pesos on a real date is skipped, one row at a time: the body is data,
 * not a promise.
 */
final class TrmDataset
{
    private const NUMBER = '/\A\d{1,10}(\.\d{1,8})?\z/';

    private const DATE = '/\A(\d{4})-(\d{2})-(\d{2})(T[\d:.]+Z?)?\z/';

    /**
     * @return non-empty-list<array{value: string, valid_from: string, valid_until: string|null}>
     *
     * @throws MarketSourceException `invalid_json` or `invalid_data`
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true, 8);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MarketSourceException('invalid_json');
        }

        if (! is_array($data) || $data === [] || ! array_is_list($data)) {
            throw new MarketSourceException('invalid_data');
        }

        $rows = [];

        foreach ($data as $row) {
            $parsed = is_array($row) ? self::row($row) : null;

            // The same start date twice: the first one (the newest) stays.
            if ($parsed !== null && ! isset($rows[$parsed['valid_from']])) {
                $rows[$parsed['valid_from']] = $parsed;
            }
        }

        if ($rows === []) {
            throw new MarketSourceException('invalid_data');
        }

        return array_values($rows);
    }

    /**
     * @param  array<mixed>  $row
     * @return array{value: string, valid_from: string, valid_until: string|null}|null
     */
    private static function row(array $row): ?array
    {
        $value = $row['valor'] ?? null;
        $validFrom = self::date($row['vigenciadesde'] ?? null);

        if (($row['unidad'] ?? null) !== 'COP' || $validFrom === null || ! is_string($value) || preg_match(self::NUMBER, $value) !== 1 || (float) $value <= 0) {
            return null;
        }

        return [
            'value' => number_format((float) $value, 4, '.', ''),
            'valid_from' => $validFrom,
            'valid_until' => self::date($row['vigenciahasta'] ?? null),
        ];
    }

    /**
     * The `Y-m-d` of «2026-10-31T00:00:00.000», or null when it is not a real date.
     */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::DATE, $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
