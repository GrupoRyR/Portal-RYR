<?php

namespace Tests\Feature\Pwa;

use Tests\TestCase;

/**
 * public/offline.html, served by the service worker without network: the
 * brand manual's Helvetica/Arial and nothing loaded from elsewhere.
 */
class OfflinePageTest extends TestCase
{
    public function test_it_uses_the_brand_font_and_no_external_assets(): void
    {
        $html = (string) file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString("font-family: Helvetica, Arial, 'Helvetica Neue', sans-serif;", $html);
        $this->assertStringNotContainsString('system-ui', $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)="(https?:)?\/\//', $html);
    }
}
