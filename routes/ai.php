<?php

use App\Http\Controllers\Mcp\ApproveController;
use App\Http\Controllers\Mcp\AuthorizeController;
use App\Http\Controllers\Mcp\RegisterClientController;
use App\Mcp\Servers\PortalServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

/*
| The portal as a custom connector for Claude (odd/tasks/conector-mcp.md),
| loaded by laravel/mcp. Everything here answers 404 while the connector is
| off (`mcp.enabled`, App\Mcp\McpSwitch).
|
| - OAuth discovery (/.well-known/*) and dynamic client registration, rate
|   limited per address (`mcp-oauth`, AppServiceProvider).
| - Passport's routes, only the ones the connector needs (Passport's own
|   are off: Passport::ignoreRoutes()). Same names, so the metadata of
|   laravel/mcp points at them.
| - The MCP server itself, for Passport tokens (guard `api`) of an active
|   connection (`mcp.connection`), limited per connection (`mcp`). Calls
|   that fail authentication are limited per address first
|   (`mcp.failures`).
*/

Route::middleware('mcp.enabled')->group(function () {
    Route::middleware('throttle:mcp-oauth')->group(function () {
        Mcp::oauthRoutes(registration: false);

        Route::post('/oauth/register', RegisterClientController::class)->name('mcp.oauth.register');
    });

    Route::prefix('oauth')->name('passport.')->group(function () {
        Route::post('/token', [AccessTokenController::class, 'issueToken'])
            ->middleware('throttle')
            ->name('token');

        Route::middleware(['web', 'throttle:20,1'])->group(function () {
            // Guests are sent to the Microsoft sign-in and come back here.
            Route::get('/authorize', [AuthorizeController::class, 'authorize'])
                ->middleware('mcp.account')
                ->name('authorizations.authorize');

            Route::middleware('auth:web')->group(function () {
                Route::post('/authorize', [ApproveController::class, 'approve'])
                    ->middleware('mcp.account')
                    ->name('authorizations.approve');

                Route::delete('/authorize', [DenyAuthorizationController::class, 'deny'])
                    ->name('authorizations.deny');
            });
        });
    });

    Mcp::web('/mcp/portal', PortalServer::class)
        ->middleware(['mcp.failures', 'auth:api', 'mcp.connection', 'throttle:mcp']);
});
