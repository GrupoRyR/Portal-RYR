<?php

namespace App\Pwa;

use App\Branding\BrandPalette;
use App\Models\Company;

/**
 * The web app manifest of the signed-in user's company (the neutral portal
 * one when signed out): name, splash colors and icons of the installed app.
 */
final class WebManifest
{
    /**
     * Status/title bar color while the app starts; the same light ground as
     * the pages' theme-color meta, so it does not jump once a page loads.
     */
    public const THEME_COLOR = '#F5F5F2';

    /**
     * @return array<string, mixed>
     */
    public static function for(?Company $company, PwaIconSet $icons): array
    {
        $name = $company?->name ?: (string) config('app.name');

        return [
            'id' => '/',
            'name' => $name,
            'short_name' => $company?->short_name ?: $name,
            'description' => 'Portal de aplicaciones',
            'lang' => 'es',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Splash screen: the company's light tint behind its icon.
            'background_color' => BrandPalette::forCompany($company)->tint,
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                ['src' => $icons->any192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons->any512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons->maskable512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }
}
