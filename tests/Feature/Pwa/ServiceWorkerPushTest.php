<?php

namespace Tests\Feature\Pwa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Avisos en el celular» in the service worker (odd/tasks/avisos.md T3):
 * a push shows the notice with the company icon, and tapping it focuses
 * or opens the announcement, only on the portal's own origin. The cache
 * rules stay as they are (ServiceWorkerTest, CachePrivacyTest).
 */
class ServiceWorkerPushTest extends TestCase
{
    use RefreshDatabase;

    private function script(): string
    {
        return $this->get('/sw.js')->assertOk()->getContent();
    }

    public function test_a_push_shows_the_notice_with_the_company_icon(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("self.addEventListener('push'", $script);
        $this->assertStringContainsString('self.registration.showNotification(', $script);
        $this->assertStringContainsString('event.waitUntil(', $script);
        // The payload's icon (the company's), the portal one otherwise.
        $this->assertStringContainsString("const NOTICE_ICON = '/icons/portal-192.png';", $script);
        $this->assertStringContainsString('icon: sameOrigin(data.icon) ?? NOTICE_ICON', $script);
        $this->assertStringContainsString('tag: ', $script);
    }

    public function test_tapping_it_focuses_or_opens_only_a_same_origin_page(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("self.addEventListener('notificationclick'", $script);
        $this->assertStringContainsString('event.notification.close()', $script);
        $this->assertStringContainsString('self.clients.matchAll(', $script);
        $this->assertStringContainsString('.focus()', $script);
        $this->assertStringContainsString('self.clients.openWindow(', $script);
        $this->assertStringContainsString('url.origin === self.location.origin ? url.href : null', $script);
    }

    public function test_the_caching_rules_are_unchanged(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("self.addEventListener('fetch'", $script);
        $this->assertStringContainsString('const BYPASS = /^\/(api\/|auth\/|livewire)/;', $script);
        $this->assertStringContainsString("if (request.method !== 'GET') return;", $script);
        $this->assertSame(1, substr_count($script, "self.addEventListener('fetch'"));
    }
}
