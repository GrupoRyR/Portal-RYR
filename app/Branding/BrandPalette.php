<?php

namespace App\Branding;

use App\Models\Company;

/**
 * The brand colors a page is painted with, as CSS custom properties.
 *
 * Only the primary color is required; everything that carries text is
 * derived from it so any company color stays readable:
 * - button: the primary, darkened in OKLCH until white text reaches 4.8:1
 *   (WCAG AA 4.5:1 plus a margin, decision 2026-10-05);
 * - tint: a very light primary for active/selected backgrounds;
 * - ink: a dark primary for text on the tint (AAA target, AA guaranteed);
 * - darkTint/darkInk: the same pair for the dark color scheme.
 *
 * Without a company (signed out) or with an invalid primary, the neutral
 * palette is used: the portal never shows a half-broken brand.
 */
final readonly class BrandPalette
{
    /** White text on the button must reach this ratio (AA 4.5:1 + margin). */
    public const BUTTON_CONTRAST = 4.8;

    private const WHITE = '#FFFFFF';

    private const STEP = 0.005;

    private const TINT_LIGHTNESS = 0.96;

    private const TINT_MAX_CHROMA = 0.025;

    private const DARK_TINT_LIGHTNESS = 0.3;

    private const DARK_TINT_MAX_CHROMA = 0.05;

    public function __construct(
        public string $primary,
        public string $accent,
        public string $dark,
        public string $button,
        public string $tint,
        public string $ink,
        public string $darkTint,
        public string $darkInk,
    ) {}

    public static function forCompany(?Company $company): self
    {
        if ($company === null) {
            return self::neutral();
        }

        return self::fromColors($company->color_primary, $company->color_accent, $company->color_dark);
    }

    public static function neutral(): self
    {
        return new self(
            primary: '#17191C',
            accent: '#5E6168',
            dark: '#17191C',
            button: '#17191C',
            tint: '#F0F0EC',
            ink: '#17191C',
            darkTint: '#2C2E33',
            darkInk: '#F2F2EF',
        );
    }

    public static function fromColors(mixed $primary, mixed $accent, mixed $dark): self
    {
        $primaryColor = HexColor::tryFrom($primary);

        if ($primaryColor === null) {
            return self::neutral();
        }

        $base = Oklch::fromHex($primaryColor);
        $tint = $base->withLightness(self::TINT_LIGHTNESS)->withChroma(min($base->chroma, self::TINT_MAX_CHROMA))->toHex();
        $darkTint = $base->withLightness(self::DARK_TINT_LIGHTNESS)->withChroma(min($base->chroma, self::DARK_TINT_MAX_CHROMA))->toHex();
        $ink = self::darkenUntil($primaryColor, $tint, Contrast::AAA_NORMAL);

        return new self(
            primary: $primaryColor->toHex(),
            accent: (HexColor::tryFrom($accent) ?? $primaryColor)->toHex(),
            dark: (HexColor::tryFrom($dark) ?? $ink)->toHex(),
            button: self::darkenUntil($primaryColor, HexColor::from(self::WHITE), self::BUTTON_CONTRAST)->toHex(),
            tint: $tint->toHex(),
            ink: $ink->toHex(),
            darkTint: $darkTint->toHex(),
            darkInk: self::lightenUntil($primaryColor, $darkTint, Contrast::AAA_NORMAL)->toHex(),
        );
    }

    /**
     * Light color scheme values, in a stable order.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        return [
            '--brand-primary' => $this->primary,
            '--brand-accent' => $this->accent,
            '--brand-dark' => $this->dark,
            '--brand-button' => $this->button,
            '--brand-tint' => $this->tint,
            '--brand-ink' => $this->ink,
        ];
    }

    /**
     * Values that change in the dark color scheme.
     *
     * @return array<string, string>
     */
    public function darkCssVariables(): array
    {
        return [
            '--brand-tint' => $this->darkTint,
            '--brand-ink' => $this->darkInk,
        ];
    }

    /**
     * Inline stylesheet for the page <head>. Every value is a HexColor that
     * was re-serialized, so the output only ever holds "#RRGGBB" values.
     */
    public function toCss(): string
    {
        return ':root{'.self::declarations($this->cssVariables()).'}'
            .'@media (prefers-color-scheme: dark){:root{'.self::declarations($this->darkCssVariables()).'}}';
    }

    /**
     * @param  array<string, string>  $variables
     */
    private static function declarations(array $variables): string
    {
        $css = '';

        foreach ($variables as $name => $value) {
            $css .= $name.':'.HexColor::from($value)->toHex().';';
        }

        return $css;
    }

    /**
     * Lower the OKLCH lightness (chroma scaled along, hue kept) until the
     * color reaches the target ratio against the background. Black is the
     * floor, so the loop always ends.
     */
    private static function darkenUntil(HexColor $color, HexColor $background, float $target): HexColor
    {
        $base = Oklch::fromHex($color);
        $candidate = $color;
        $lightness = $base->lightness;

        while (Contrast::ratio($candidate, $background) < $target && $lightness > 0) {
            $lightness = max(0.0, $lightness - self::STEP);
            $scale = $base->lightness > 0 ? $lightness / $base->lightness : 0;
            $candidate = $base->withLightness($lightness)->withChroma($base->chroma * $scale)->toHex();
        }

        return $candidate;
    }

    /**
     * Raise the OKLCH lightness (hue kept, chroma clipped to the gamut) until
     * the color reaches the target ratio against a dark background.
     */
    private static function lightenUntil(HexColor $color, HexColor $background, float $target): HexColor
    {
        $base = Oklch::fromHex($color);
        $lightness = max($base->lightness, 0.7);
        $candidate = $base->withLightness($lightness)->toHex();

        while (Contrast::ratio($candidate, $background) < $target && $lightness < 1) {
            $lightness = min(1.0, $lightness + self::STEP);
            $candidate = $base->withLightness($lightness)->toHex();
        }

        return $candidate;
    }
}
