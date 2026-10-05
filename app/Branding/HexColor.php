<?php

namespace App\Branding;

use InvalidArgumentException;

/**
 * An opaque sRGB color written as "#RRGGBB".
 *
 * This is the only way brand colors reach CSS: anything that is not exactly
 * a hash followed by six hex digits is rejected, and output is always
 * rebuilt from the parsed channels, so stored values can never inject CSS.
 */
final readonly class HexColor
{
    private function __construct(
        public int $red,
        public int $green,
        public int $blue,
    ) {}

    public static function tryFrom(mixed $value): ?self
    {
        // \z (not $) so a trailing newline is rejected too.
        if (! is_string($value) || preg_match('/\A#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return new self(hexdec($m[1]), hexdec($m[2]), hexdec($m[3]));
    }

    public static function from(mixed $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException('Expected a color in the form #RRGGBB.');
    }

    public static function fromRgb(int $red, int $green, int $blue): self
    {
        foreach ([$red, $green, $blue] as $channel) {
            if ($channel < 0 || $channel > 255) {
                throw new InvalidArgumentException('Color channels must be between 0 and 255.');
            }
        }

        return new self($red, $green, $blue);
    }

    public function toHex(): string
    {
        return sprintf('#%02X%02X%02X', $this->red, $this->green, $this->blue);
    }

    /**
     * WCAG 2.x relative luminance (0 for black, 1 for white).
     */
    public function relativeLuminance(): float
    {
        return 0.2126 * self::linear($this->red)
            + 0.7152 * self::linear($this->green)
            + 0.0722 * self::linear($this->blue);
    }

    /**
     * sRGB channel (0–255) to linear light (0–1).
     */
    public static function linear(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    public function __toString(): string
    {
        return $this->toHex();
    }
}
