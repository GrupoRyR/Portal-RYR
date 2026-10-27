<?php

use App\Http\Middleware\AuthorizeIntegrationRequest;
use App\Http\Middleware\RequireRecentMicrosoftLogin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API for external systems (brief §6b): /api/v1, Bearer tokens.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On cPanel shared hosting, Apache terminates TLS in front of PHP.
        // Only a proxy on the same machine may tell us the original scheme,
        // host, port and client IP; forwarded headers from any other address
        // are ignored so remote clients cannot spoof them.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Browser hardening headers on every response, errors and API too.
        $middleware->append(SecurityHeaders::class);

        // spatie/laravel-permission route middleware ("role:a|b").
        $middleware->alias([
            'role' => RoleMiddleware::class,
            // Recent Microsoft re-authentication (App\Auth\RecentMicrosoftLogin).
            'microsoft.recent' => RequireRecentMicrosoftLogin::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // API (routes/api.php): token abilities and integration checks.
            'abilities' => CheckAbilities::class,
            'integration' => AuthorizeIntegrationRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes always answer JSON (401, 403, 404, 422, 429...), even
        // without an `Accept: application/json` header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A missing app or route: no model class names in API answers.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
