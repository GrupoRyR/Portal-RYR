<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum (API for external systems, brief §6b)
|--------------------------------------------------------------------------
|
| Sanctum is used only for the Bearer tokens of external systems
| (App\Models\Integration). The portal has no SPA, so:
|
| - no stateful domains and no session guard: a signed-in admin's cookie
|   never authenticates an API call;
| - no /sanctum/csrf-cookie route;
| - tokens never expire on their own: they are revoked or rotated in
|   Administración → Integraciones;
| - `last_used_at` of the token is not written on every request:
|   App\Http\Middleware\AuthorizeIntegrationRequest records the last use
|   on the integration at most once a minute.
|
*/

return [

    'stateful' => [],

    'guard' => [],

    'expiration' => null,

    // Recognisable prefix of the tokens (secret scanners, logs review).
    'token_prefix' => 'ryr_',

    'last_used_at' => false,

    'routes' => false,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
