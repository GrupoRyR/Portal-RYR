<?php

namespace App\Pwa;

use App\Settings\PortalSettings;

/**
 * The service worker served at /sw.js: resources/js/sw.js with its
 * settings in front (`self.PORTAL = {...};`):
 *
 * - version: tied to the Vite build manifest, the worker's own code and the
 *   offline page, so every deploy that changes them gets new caches and
 *   drops the old ones;
 * - precache: the built CSS/JS of the home screen and its Latin fonts, read
 *   from public/build/manifest.json, plus the offline page;
 * - maxAgeMs: the idle timeout of Configuración; a saved home page older
 *   than a session could live is never shown.
 */
final class ServiceWorkerScript
{
    /** Vite entries the home screen loads. */
    public const HOME_ENTRIES = ['resources/css/app.css', 'resources/js/home.js'];

    public const OFFLINE_PAGE = '/offline.html';

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly ?string $viteManifestPath = null,
    ) {}

    public function render(): string
    {
        $template = (string) file_get_contents(resource_path('js/sw.js'));
        $manifestJson = (string) @file_get_contents($this->viteManifestPath ?? public_path('build/manifest.json'));

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = json_decode($manifestJson, true) ?: [];

        $offlinePage = (string) @file_get_contents(public_path(ltrim(self::OFFLINE_PAGE, '/')));

        $config = [
            'version' => substr(hash('sha256', $manifestJson."\n".$template."\n".$offlinePage), 0, 12),
            'precache' => [...$this->homeAssets($manifest), self::OFFLINE_PAGE],
            'maxAgeMs' => $this->settings->idleTimeoutMinutes() * 60 * 1000,
        ];

        return 'self.PORTAL = '.json_encode($config, JSON_UNESCAPED_SLASHES).";\n".$template;
    }

    /**
     * Built files of the home entries and the chunks they import. Of the
     * fonts, only the Latin subset: the latin-ext and vietnamese files are
     * only downloaded for characters Spanish does not use (unicode-range).
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return list<string>
     */
    private function homeAssets(array $manifest): array
    {
        $files = [];
        $pending = self::HOME_ENTRIES;
        $seen = [];

        while ($pending !== []) {
            $key = array_shift($pending);

            if (isset($seen[$key]) || ! isset($manifest[$key]['file'])) {
                continue;
            }

            $seen[$key] = true;
            $chunk = $manifest[$key];
            $files[] = $chunk['file'];

            foreach ([...($chunk['css'] ?? []), ...($chunk['assets'] ?? [])] as $file) {
                $files[] = $file;
            }

            array_push($pending, ...($chunk['imports'] ?? []));
        }

        $files = array_filter($files, fn (string $file) => ! str_ends_with($file, '.woff2') || str_contains($file, '-latin-wght-'));

        return array_values(array_unique(array_map(fn (string $file) => '/build/'.$file, $files)));
    }
}
