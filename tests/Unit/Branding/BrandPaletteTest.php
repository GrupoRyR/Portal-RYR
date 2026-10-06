<?php

namespace Tests\Unit\Branding;

use App\Branding\BrandPalette;
use App\Branding\Contrast;
use App\Branding\HexColor;
use App\Models\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandPaletteTest extends TestCase
{
    private const WHITE = '#FFFFFF';

    public function test_the_segurex_button_is_darkened_until_white_text_reaches_the_target(): void
    {
        $palette = BrandPalette::fromColors('#FF671F', '#666666', '#8F360B');

        $this->assertSame('#FF671F', $palette->primary);
        // Target 4.8:1 (decision 2026-10-05) leaves a margin over AA 4.5:1.
        $this->assertGreaterThanOrEqual(BrandPalette::BUTTON_CONTRAST, Contrast::ratio(self::WHITE, $palette->button));
        $this->assertSame(4.8, BrandPalette::BUTTON_CONTRAST);
        $this->assertCloseTo('#C4470F', $palette->button, 6);
    }

    public function test_the_derivados_button_keeps_the_primary_color(): void
    {
        $palette = BrandPalette::fromColors('#183F94', '#3E60A8', '#03256C');

        $this->assertSame('#183F94', $palette->button);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function primaries(): array
    {
        return [
            'Segurex' => ['#FF671F'],
            'Derivados' => ['#183F94'],
            'Andina (test brand)' => ['#0F8A7E'],
            'bright yellow' => ['#FFE600'],
            'white' => ['#FFFFFF'],
            'black' => ['#000000'],
            'pure red' => ['#FF0000'],
            'pure blue' => ['#0000FF'],
        ];
    }

    #[DataProvider('primaries')]
    public function test_derived_colors_always_meet_aa(string $primary): void
    {
        $palette = BrandPalette::fromColors($primary, null, null);

        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio(self::WHITE, $palette->button), 'white on button');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($palette->tint, $palette->ink), 'ink on tint');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($palette->darkTint, $palette->darkInk), 'ink on tint (dark mode)');
    }

    #[DataProvider('primaries')]
    public function test_the_tint_is_a_very_light_version_of_the_primary(string $primary): void
    {
        $palette = BrandPalette::fromColors($primary, null, null);

        // Light enough that body text (#17191C) on it stays far above AAA.
        $this->assertGreaterThanOrEqual(14.0, Contrast::ratio('#17191C', $palette->tint));
    }

    public function test_segurex_tint_and_ink_follow_the_design_system(): void
    {
        $palette = BrandPalette::fromColors('#FF671F', '#666666', '#8F360B');

        $this->assertCloseTo('#FFEFE6', $palette->tint, 8);
        $this->assertCloseTo('#8F360B', $palette->ink, 16);
    }

    public function test_a_null_company_gets_the_neutral_palette(): void
    {
        $palette = BrandPalette::forCompany(null);

        $this->assertEquals(BrandPalette::neutral(), $palette);
        $this->assertSame('#17191C', $palette->primary);
        $this->assertSame('#17191C', $palette->button);
    }

    public function test_a_company_fills_the_palette_from_its_colors(): void
    {
        $company = new Company([
            'color_primary' => '#183f94',
            'color_accent' => '#3E60A8',
            'color_dark' => '#03256C',
        ]);

        $palette = BrandPalette::forCompany($company);

        $this->assertSame('#183F94', $palette->primary);
        $this->assertSame('#3E60A8', $palette->accent);
        $this->assertSame('#03256C', $palette->dark);
    }

    public function test_an_invalid_primary_falls_back_to_the_neutral_palette(): void
    {
        $palette = BrandPalette::fromColors('#fff;}body{background:url(x)}', '#3E60A8', '#03256C');

        $this->assertEquals(BrandPalette::neutral(), $palette);
    }

    public function test_invalid_accent_and_dark_fall_back_to_derived_colors(): void
    {
        $palette = BrandPalette::fromColors('#183F94', 'red;}', '</style><script>');

        $this->assertSame('#183F94', $palette->accent);
        $this->assertSame($palette->ink, $palette->dark);
    }

    public function test_css_variables_cover_every_brand_token(): void
    {
        $variables = BrandPalette::fromColors('#FF671F', '#666666', '#8F360B')->cssVariables();

        $this->assertSame(
            ['--brand-primary', '--brand-accent', '--brand-dark', '--brand-button', '--brand-tint', '--brand-ink'],
            array_keys($variables),
        );
    }

    public function test_the_css_declares_light_and_dark_values(): void
    {
        $css = BrandPalette::fromColors('#FF671F', '#666666', '#8F360B')->toCss();

        $this->assertStringStartsWith(':root{--brand-primary:#FF671F;', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root{--brand-tint:', $css);
    }

    public function test_the_css_never_contains_injected_content(): void
    {
        $css = BrandPalette::fromColors('#FF671F', '#666666;}body{display:none', '</style><script>alert(1)</script>')->toCss();

        $this->assertMatchesRegularExpression(
            '/\A:root\{(--brand-[a-z]+:#[0-9A-F]{6};)+\}@media \(prefers-color-scheme: dark\)\{:root\{(--brand-[a-z]+:#[0-9A-F]{6};)+\}\}\z/',
            $css,
        );
    }

    private function assertCloseTo(string $expected, string $actual, int $tolerance): void
    {
        $e = HexColor::from($expected);
        $a = HexColor::from($actual);

        $this->assertLessThanOrEqual(
            $tolerance,
            max(abs($e->red - $a->red), abs($e->green - $a->green), abs($e->blue - $a->blue)),
            "{$actual} is not within {$tolerance} per channel of {$expected}",
        );
    }
}
