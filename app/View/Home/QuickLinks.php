<?php

namespace App\View\Home;

use App\Models\Company;
use Illuminate\Support\Str;

/**
 * Microsoft 365 shortcuts: Outlook, Teams and OneDrive for everyone, then
 * the company's own links (Administración → Empresas). SharePoint is never
 * a default because its address depends on the tenant.
 *
 * Default addresses (checked 2026-10-05):
 * - Outlook: https://outlook.office365.com/owa/ — learn.microsoft.com/exchange/outlook-web-app-exchange-2013-help
 * - Teams: https://teams.microsoft.com/ — learn.microsoft.com/microsoftteams/teams-client-desktop-admin
 * - OneDrive: https://onedrive.live.com/ (redirects work accounts to their
 *   tenant) — learn.microsoft.com/dynamics365/business-central/admin-onedrive-integration-onpremises
 */
final class QuickLinks
{
    public const DEFAULTS = [
        'Outlook' => 'https://outlook.office365.com/owa/',
        'Teams' => 'https://teams.microsoft.com/',
        'OneDrive' => 'https://onedrive.live.com/',
    ];

    private const ICONS = ['outlook', 'teams', 'onedrive', 'sharepoint'];

    /**
     * @return list<QuickLink>
     */
    public static function for(?Company $company): array
    {
        $links = [];

        foreach (self::DEFAULTS as $label => $url) {
            $links[mb_strtolower($label)] = new QuickLink($label, $url, mb_strtolower($label));
        }

        foreach ($company?->quick_links ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = trim((string) ($link['label'] ?? ''));
            $url = trim((string) ($link['url'] ?? ''));

            if ($label === '' || ! self::isHttps($url)) {
                continue;
            }

            $key = mb_strtolower($label);

            // A company link named like a default replaces its address.
            $links[$key] = isset($links[$key])
                ? new QuickLink($links[$key]->label, $url, $links[$key]->icon)
                : new QuickLink($label, $url, in_array($key, self::ICONS, true) ? $key : 'link');
        }

        return array_values($links);
    }

    private static function isHttps(string $url): bool
    {
        return Str::startsWith(mb_strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
