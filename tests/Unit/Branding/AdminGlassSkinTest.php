<?php

namespace Tests\Unit\Branding;

use PHPUnit\Framework\TestCase;

/**
 * Invariants of the administration skin (resources/css/glass.css, section
 * 7) that a browser test would only catch by accident.
 */
class AdminGlassSkinTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../resources/css/glass.css');
    }

    public function test_the_content_panel_never_traps_fixed_dialogs(): void
    {
        $block = $this->rule('.g-admin-main');

        // Each of these makes the panel the containing block or the stacking
        // context of the views' fixed dialogs (integrations).
        foreach (['backdrop-filter', 'transform', 'filter', 'z-index'] as $property) {
            $this->assertDoesNotMatchRegularExpression('/(^|[;\s{])'.preg_quote($property, '/').'\s*:/', $block, $property);
        }
    }

    public function test_the_content_panel_follows_the_theme_without_color_mix(): void
    {
        $block = $this->rule('.g-admin-main');

        // A color-mix() gets a static light fallback at build time, which
        // older browsers would show in dark mode too.
        $this->assertStringContainsString('background-color: var(--color-ground);', $block);
        $this->assertStringNotContainsString('color-mix', $block);
    }

    public function test_only_enabled_controls_get_the_filled_button_finish(): void
    {
        preg_match('/\n\s*([^\n{}]*\.bg-brand-button[^\n{}]*)\{/', self::css(), $match);

        $this->assertNotEmpty($match, 'The filled button skin is missing.');
        $this->assertStringContainsString(':is(a, button, summary).bg-brand-button', $match[1]);
        $this->assertStringContainsString(':not(:disabled)', $match[1]);
    }

    /**
     * Declarations of the first rule whose selector is exactly $selector.
     */
    private function rule(string $selector): string
    {
        $found = preg_match('/\n\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', self::css(), $match);

        $this->assertSame(1, $found, "No {$selector} rule.");

        return $match[1];
    }
}
