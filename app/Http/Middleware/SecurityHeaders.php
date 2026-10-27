<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware: browser hardening headers on every response the app
 * answers, including errors and the API. A header the response already
 * carries (e.g. its own Content-Security-Policy) is left as it is.
 *
 * - nosniff: files are only run or rendered as their declared type;
 * - X-Frame-Options and CSP frame-ancestors: the portal cannot be framed
 *   (clickjacking); the CSP carries no other directive on purpose, since a
 *   full policy needs Livewire/Alpine to run without inline code first;
 * - Referrer-Policy: other sites only see the origin, never the path;
 * - Permissions-Policy: no page needs the camera, microphone or location.
 *
 * HSTS goes only on HTTPS responses, as Laravel sees them: direct TLS, or
 * X-Forwarded-Proto from the trusted local proxy (bootstrap/app.php). This
 * holds whichever layer of the hosting terminates TLS, and the app never
 * redirects, so it cannot loop; the redirect from HTTP is the hosting's
 * Force HTTPS Redirect. public/.htaccess also sends HSTS when Apache itself
 * serves TLS, for the case where Laravel does not see HTTPS. One HTTPS page
 * is enough: browsers apply HSTS to the whole host.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = self::HEADERS;

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
