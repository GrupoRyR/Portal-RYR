<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `mcp.failures`, first on /mcp/portal: at most {@see MAX_FAILURES} calls a
 * minute per address may end in 401 (bad, expired or revoked tokens);
 * past that, every call from the address gets 429 before authentication
 * runs. `throttle:mcp` cannot do it: Laravel always sorts throttling after
 * authentication. Calls that authenticate never count, so connections
 * that share Claude's addresses keep their own budget (`throttle:mcp`).
 */
class ThrottleFailedMcpCalls
{
    public const MAX_FAILURES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'mcp-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return response()->json(['message' => 'Too Many Attempts.'], 429, [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }
}
