<?php

namespace Tests\Unit\Branding;

use App\Branding\Contrast;
use App\Branding\HexColor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContrastTest extends TestCase
{
    /**
     * Reference ratios from the approved design system (Tokens.dc.html) and
     * the WCAG 2.x relative luminance formula.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function knownRatios(): array
    {
        return [
            'white on Segurex orange' => ['#FFFFFF', '#FF671F', 2.91],
            'white on Segurex button' => ['#FFFFFF', '#C4470F', 4.94],
            'white on Derivados blue' => ['#FFFFFF', '#183F94', 9.64],
            'black on white' => ['#000000', '#FFFFFF', 21.0],
            'same color' => ['#5E6168', '#5E6168', 1.0],
        ];
    }

    #[DataProvider('knownRatios')]
    public function test_it_computes_known_ratios(string $a, string $b, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, Contrast::ratio($a, $b), 0.05);
    }

    public function test_the_ratio_is_symmetric(): void
    {
        $this->assertSame(Contrast::ratio('#FFFFFF', '#183F94'), Contrast::ratio('#183F94', '#FFFFFF'));
    }

    public function test_it_accepts_hex_color_objects(): void
    {
        $this->assertEqualsWithDelta(2.91, Contrast::ratio(HexColor::from('#ffffff'), HexColor::from('#ff671f')), 0.05);
    }

    public function test_it_checks_the_aa_threshold_for_normal_text(): void
    {
        $this->assertFalse(Contrast::meetsAa('#FFFFFF', '#FF671F'));
        $this->assertTrue(Contrast::meetsAa('#FFFFFF', '#C4470F'));
    }

    public function test_it_checks_the_aa_threshold_for_large_text(): void
    {
        // 3:1 is enough for large text (at least 24px, or 18.66px bold).
        $this->assertFalse(Contrast::meetsAa('#FFFFFF', '#FF671F', largeText: true));
        $this->assertTrue(Contrast::meetsAa('#FFFFFF', '#C4470F', largeText: true));
    }

    public function test_it_rejects_anything_that_is_not_a_six_digit_hex_color(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Contrast::ratio('#fff;}body{', '#FFFFFF');
    }
}
