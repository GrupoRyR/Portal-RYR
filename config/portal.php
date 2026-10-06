<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super administrators
    |--------------------------------------------------------------------------
    |
    | Emails that receive the `super_admin` role at each Microsoft sign-in
    | (and lose it when removed from this list). Comma-separated in the
    | environment; compared lowercase.
    |
    */

    'super_admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('PORTAL_SUPER_ADMIN_EMAILS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Portal administrators group
    |--------------------------------------------------------------------------
    |
    | Members of this Microsoft 365 group (in any company tenant) get the
    | `portal_admin` role at sign-in and lose it when they leave the group.
    |
    */

    'admin_group' => 'APP-PortalAdmin',

    /*
    |--------------------------------------------------------------------------
    | Emergency access path
    |--------------------------------------------------------------------------
    |
    | Unlinked path of the local emergency sign-in (password + TOTP), used
    | only when Microsoft sign-in is unavailable. Not a secret by itself:
    | the account is protected by its password, 2FA and throttling.
    |
    */

    'emergency_path' => trim((string) env('PORTAL_EMERGENCY_PATH', 'acceso-emergencia'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Allowed application hosts
    |--------------------------------------------------------------------------
    |
    | Web apps of the catalog may only point at `https://` URLs on these
    | hosts (App\Catalog\AppUrlPolicy). A plain domain also covers its
    | subdomains; `*` matches part of one DNS label only. Default of the
    | list managed in Administración → Configuración.
    |
    */

    'allowed_app_hosts' => [
        'segurex.com.co',
        'derivadoscol.com.co',
        'sgx-*.vercel.app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults of Configuración
    |--------------------------------------------------------------------------
    |
    | App\Settings\PortalSettings uses these until a super admin saves
    | Administración → Configuración; saved values live in the database.
    | The idle timeout defaults to config('session.lifetime').
    |
    | - support_url: ticket platform of the «Soporte TI» button (none: the
    |   button is hidden).
    | - microsoft_logout: also end the Microsoft session when signing out.
    | - api_status_ttl_hours: hours after which an app reported as failing
    |   goes back to normal when no new report arrives (Integraciones).
    |
    */

    'support_url' => env('PORTAL_SUPPORT_URL') ?: null,

    'microsoft_logout' => false,

    'api_status_ttl_hours' => 2,

];
