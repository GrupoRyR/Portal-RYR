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
     * Status/title bar color while the app starts without a company: the
     * light ground of the signed-out pages. A company gets its --brand-dark,
     * the band behind its logo (see self::themeColor()).
     */
    public const THEME_COLOR = '#F5F5F2';

    /** Bar color of the pages and the installed app of a company. */
    public static function themeColor(?Company $company): string
    {
        return $company === null ? self::THEME_COLOR : BrandPalette::forCompany($company)->dark;
    }

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
            'theme_color' => self::themeColor($company),
            'icons' => [
                ['src' => $icons->any192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons->any512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons->maskable512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }
}
