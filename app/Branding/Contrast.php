<?php

namespace App\Branding;

/**
 * WCAG 2.x contrast ratios between two opaque colors.
 *
 * @see https://www.w3.org/TR/WCAG22/#dfn-contrast-ratio
 */
final class Contrast
{
    /** Minimum ratio for normal text (WCAG 1.4.3, level AA). */
    public const AA_NORMAL = 4.5;

    /** Minimum ratio for large text and UI components (level AA). */
    public const AA_LARGE = 3.0;

    /** Minimum ratio for normal text at level AAA. */
    public const AAA_NORMAL = 7.0;

    /**
     * Ratio from 1 (no contrast) to 21 (black on white). Order does not matter.
     *
     * @throws \InvalidArgumentException when a string is not "#RRGGBB"
     */
    public static function ratio(HexColor|string $a, HexColor|string $b): float
    {
        $la = self::color($a)->relativeLuminance();
        $lb = self::color($b)->relativeLuminance();

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function meetsAa(HexColor|string $foreground, HexColor|string $background, bool $largeText = false): bool
    {
        return self::ratio($foreground, $background) >= ($largeText ? self::AA_LARGE : self::AA_NORMAL);
    }

    private static function color(HexColor|string $value): HexColor
    {
        return $value instanceof HexColor ? $value : HexColor::from($value);
    }
}
