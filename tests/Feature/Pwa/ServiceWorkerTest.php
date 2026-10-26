<?php

namespace Tests\Feature\Pwa;

use App\Pwa\ServiceWorkerScript;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceWorkerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{version: string, precache: list<string>, maxAgeMs: int}
     */
    private function config(string $script): array
    {
        $this->assertSame(1, preg_match('/^self\.PORTAL = (\{.*?\});\n/', $script, $match));

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function viteManifest(): array
    {
        return json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    }

    public function test_it_is_served_for_the_whole_site_and_always_revalidated(): void
    {
        $response = $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertHeader('Service-Worker-Allowed', '/');

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("self.addEventListener('fetch'", $response->getContent());
    }

    public function test_it_precaches_only_the_built_assets_of_the_home_screen(): void
    {
        $vite = $this->viteManifest();
        $precache = $this->config($this->get('/sw.js')->getContent())['precache'];

        $this->assertContains('/build/'.$vite['resources/js/home.js']['file'], $precache);
        $this->assertContains('/build/'.$vite['resources/css/app.css']['file'], $precache);
        $this->assertContains('/offline.html', $precache);
        $this->assertNotContains('/build/'.$vite['resources/js/livewire.js']['file'], $precache);

        // No web fonts: the brand manual uses the system Helvetica/Arial.
        $fonts = array_values(array_filter($precache, fn (string $url) => str_ends_with($url, '.woff2')));
        $this->assertSame([], $fonts);
    }

    public function test_the_cache_version_follows_the_build(): void
    {
        $build = $this->config((new ServiceWorkerScript(app(PortalSettings::class)))->render())['version'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $build);

        $manifest = tempnam(sys_get_temp_dir(), 'vite');
        file_put_contents($manifest, json_encode(['resources/js/home.js' => ['file' => 'assets/home-NEW.js', 'isEntry' => true]]));

        try {
            $other = $this->config((new ServiceWorkerScript(app(PortalSettings::class), $manifest))->render());
        } finally {
            unlink($manifest);
        }

        $this->assertNotSame($build, $other['version']);
        $this->assertContains('/build/assets/home-NEW.js', $other['precache']);
    }

    public function test_saved_pages_live_no_longer_than_an_idle_session(): void
    {
        app(PortalSettings::class)->update(['idle_timeout_minutes' => 480], null);

        $this->assertSame(480 * 60 * 1000, $this->config($this->get('/sw.js')->getContent())['maxAgeMs']);
    }

    public function test_it_never_handles_sign_in_api_or_livewire_requests(): void
    {
        $script = $this->get('/sw.js')->getContent();

        $this->assertStringContainsString("if (request.method !== 'GET') return;", $script);
        $this->assertStringContainsString('const BYPASS = /^\/(api\/|auth\/|livewire)/;', $script);
    }

    public function test_the_offline_page_is_self_contained_and_in_spanish(): void
    {
        $html = (string) file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString('<html lang="es">', $html);
        $this->assertStringContainsString('Sin conexión', $html);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:/', $html);
    }
}
