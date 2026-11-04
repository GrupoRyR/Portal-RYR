<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Multi-tenant Entra ID app registered once in the Segurex tenant. The
    // `organizations` endpoint accepts any work account; the portal then
    // admits only tenants of active companies (App\Tenancy\TenantDirectory).
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        // Must be absolute: the SocialiteProviders manager does not expand
        // relative redirect URIs.
        'redirect' => env(
            'MICROSOFT_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/microsoft/callback',
        ),
        'tenant' => env('MICROSOFT_TENANT', 'organizations'),
    ],

    // Claude API key of the portal assistant (config/assistant.php). Set on
    // the server only; never in the repository.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    // FRED (St. Louis Fed) key for Brent and the input prices of the
    // markets band (App\Markets\MarketSync). Free; set on the server only.
    // Without it those series are skipped.
    'fred' => [
        'key' => env('FRED_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
