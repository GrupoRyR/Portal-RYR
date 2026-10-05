<?php

namespace App\Branding;

/**
 * A color in OKLCH (perceptual lightness, chroma, hue in radians).
 *
 * Used to darken or lighten a brand color without shifting its hue the way
 * HSL does. Conversions follow Björn Ottosson's OKLab reference matrices.
 *
 * @see https://bottosson.github.io/posts/oklab/
 */
final readonly class Oklch
{
    public function __construct(
        public float $lightness,
        public float $chroma,
        public float $hue,
    ) {}

    public static function fromHex(HexColor $color): self
    {
        $r = HexColor::linear($color->red);
        $g = HexColor::linear($color->green);
        $b = HexColor::linear($color->blue);

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return new self($lightness, sqrt($a * $a + $bb * $bb), atan2($bb, $a));
    }

    public function withLightness(float $lightness): self
    {
        return new self(max(0.0, min(1.0, $lightness)), $this->chroma, $this->hue);
    }

    public function withChroma(float $chroma): self
    {
        return new self($this->lightness, max(0.0, $chroma), $this->hue);
    }

    /**
     * Nearest displayable sRGB color: chroma is reduced (lightness and hue
     * kept) until the color fits the sRGB gamut.
     */
    public function toHex(): HexColor
    {
        $chroma = $this->chroma;

        while ($chroma > 0 && ! self::inGamut($this->toLinearRgb($chroma))) {
            $chroma = max(0.0, $chroma - 0.002);
        }

        return HexColor::fromRgb(...array_map(
            fn (float $c) => (int) round(self::encode(max(0.0, min(1.0, $c))) * 255),
            $this->toLinearRgb($chroma),
        ));
    }

    /**
     * @return array{float, float, float}
     */
    private function toLinearRgb(float $chroma): array
    {
        $a = $chroma * cos($this->hue);
        $b = $chroma * sin($this->hue);

        $l = ($this->lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($this->lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($this->lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /**
     * @param  array{float, float, float}  $rgb
     */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $c) {
            if ($c < -0.0001 || $c > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Linear light (0–1) to the sRGB transfer curve (0–1).
     */
    private static function encode(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }
}
