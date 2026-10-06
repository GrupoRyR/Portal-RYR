<?php

namespace App\Http\Middleware;

use App\Models\Integration;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Runs after `auth:sanctum` on every API route: the caller must be an
 * external system (App\Models\Integration) that is not revoked, calling
 * from one of its allowed IPs. Then the last use is recorded.
 */
class AuthorizeIntegrationRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $integration = $request->user();

        if (! $integration instanceof Integration || $integration->isRevoked()) {
            throw new AuthenticationException;
        }

        if (! $integration->allowsIp($request->ip())) {
            throw new AccessDeniedHttpException('This IP address is not allowed for this token.');
        }

        $integration->recordUse();

        return $next($request);
    }
}
