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
            ['--brand-primary', '--brand-accent', '--brand-dark', '--brand-button', '--brand-tint', '--brand-ink', '--brand-text'],
            array_keys($variables),
        );
    }

    public function test_dark_css_variables_cover_the_tokens_that_change_at_night(): void
    {
        $variables = BrandPalette::fromColors('#FF671F', '#666666', '#8F360B')->darkCssVariables();

        $this->assertSame(['--brand-tint', '--brand-ink', '--brand-text'], array_keys($variables));
    }

    public function test_the_segurex_manual_palette_is_used_as_given(): void
    {
        $palette = BrandPalette::fromColors('#F26522', '#F26522', '#58595B',
            button: '#C6531C', ink: '#943E15', text: '#58595B', darkInk: '#F26522', darkText: '#A8A9AA');

        $this->assertSame('#F26522', $palette->accent);
        $this->assertSame('#C6531C', $palette->button);
        $this->assertSame('#943E15', $palette->ink);
        $this->assertSame('#58595B', $palette->text);
        $this->assertSame('#58595B', $palette->dark);
        $this->assertSame('#F26522', $palette->darkInk);
        $this->assertSame('#A8A9AA', $palette->darkText);
    }

    public function test_the_derivados_manual_palette_is_used_as_given(): void
    {
        $palette = BrandPalette::fromColors('#0B5FA5', '#0B5FA5', '#073B66',
            button: '#0B5FA5', ink: '#073B66', text: '#073B66', darkInk: '#4D8ABD', darkText: '#4D8ABD');

        $this->assertSame('#0B5FA5', $palette->button);
        $this->assertSame('#073B66', $palette->ink);
        $this->assertSame('#073B66', $palette->text);
        $this->assertSame('#073B66', $palette->dark);
        $this->assertSame('#4D8ABD', $palette->darkInk);
        $this->assertSame('#4D8ABD', $palette->darkText);
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function manualPalettes(): array
    {
        return [
            'Segurex' => ['#F26522', '#C6531C', '#943E15', '#F26522', '#A8A9AA'],
            'Derivados' => ['#0B5FA5', '#0B5FA5', '#073B66', '#4D8ABD', '#4D8ABD'],
        ];
    }

    #[DataProvider('manualPalettes')]
    public function test_manual_palettes_meet_aa_in_both_schemes(string $primary, string $button, string $ink, string $darkInk, string $darkText): void
    {
        $palette = BrandPalette::fromColors($primary, $primary, null,
            button: $button, ink: $ink, text: $ink, darkInk: $darkInk, darkText: $darkText);

        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio(self::WHITE, $palette->button), 'white on button');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($palette->tint, $palette->ink), 'ink on tint');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio(self::WHITE, $palette->text), 'text on white');
        // The dark tint is deepened until the manual's dark ink keeps AA on it.
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($palette->darkTint, $palette->darkInk), 'ink on tint (dark mode)');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio(BrandPalette::DARK_SURFACE, $palette->darkInk), 'ink on surface (dark mode)');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio(BrandPalette::DARK_SURFACE, $palette->darkText), 'text on surface (dark mode)');
    }

    public function test_manual_colors_below_aa_are_replaced_by_derived_ones(): void
    {
        // White on #F26522 is only 3.15:1, and #F26522 is too light as text.
        $palette = BrandPalette::fromColors('#F26522', '#F26522', '#58595B',
            button: '#F26522', ink: '#F26522', text: '#F26522', darkInk: '#58595B', darkText: '#0B5FA5');
        $derived = BrandPalette::fromColors('#F26522', '#F26522', '#58595B');

        $this->assertSame($derived->button, $palette->button);
        $this->assertSame($derived->ink, $palette->ink);
        $this->assertSame($derived->text, $palette->text);
        $this->assertSame($derived->darkInk, $palette->darkInk);
        $this->assertSame($derived->darkText, $palette->darkText);
    }

    public function test_without_manual_colors_text_follows_the_ink(): void
    {
        $palette = BrandPalette::fromColors('#183F94', '#3E60A8', '#03256C');

        $this->assertSame($palette->ink, $palette->text);
        $this->assertSame($palette->darkInk, $palette->darkText);
    }

    public function test_a_company_fills_the_palette_from_its_manual_colors(): void
    {
        $company = new Company([
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#c6531c',
            'color_ink' => '#943E15',
            'color_text' => '#58595B',
            'color_ink_dark_mode' => '#F26522',
            'color_text_dark_mode' => '#A8A9AA',
        ]);

        $palette = BrandPalette::forCompany($company);

        $this->assertSame('#C6531C', $palette->button);
        $this->assertSame('#943E15', $palette->ink);
        $this->assertSame('#58595B', $palette->text);
        $this->assertSame('#F26522', $palette->darkInk);
        $this->assertSame('#A8A9AA', $palette->darkText);
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
