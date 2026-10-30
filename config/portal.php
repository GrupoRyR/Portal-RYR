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
    | Members of this Microsoft group get the `portal_admin` role at sign-in
    | and lose it when they leave it (App\Auth\PortalAdminGroup). The group
    | is identified by its object ID inside one tenant (Segurex), never by
    | its name; with either ID missing nobody is a portal admin. The name is
    | only shown in the admin pages.
    |
    */

    'admin_group' => 'APP-PortalAdmin',

    'admin_group_id' => strtolower(trim((string) env('PORTAL_ADMIN_GROUP_ID', ''))) ?: null,

    'admin_tenant_id' => strtolower(trim((string) env('PORTAL_ADMIN_TENANT_ID', ''))) ?: null,

    /*
    |--------------------------------------------------------------------------
    | Re-authentication age
    |--------------------------------------------------------------------------
    |
    | Seconds within which the id_token `auth_time` of a "confirm it is
    | really you" sign-in must fall (App\Auth\RecentMicrosoftLogin). Needs
    | the `auth_time` optional claim on the app registration's ID token.
    |
    */

    'reauth_max_age_seconds' => (int) env('PORTAL_REAUTH_MAX_AGE_SECONDS', 300),

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

    /*
    |--------------------------------------------------------------------------
    | Claude connector (MCP)
    |--------------------------------------------------------------------------
    |
    | The portal as a custom MCP connector for Claude (routes/ai.php,
    | odd/tasks/conector-mcp.md). Off until PORTAL_MCP_ENABLED=true: while
    | off, the server, the OAuth routes and the metadata answer 404. Read
    | only through App\Mcp\McpSwitch.
    |
    */

    'mcp' => [
        // Only true, 1, on or yes turn it on; anything else (off, no…) is off.
        'enabled' => filter_var(env('PORTAL_MCP_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

];
