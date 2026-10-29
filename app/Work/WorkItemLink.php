<?php

namespace App\Work;

/**
 * The «Ver tarea» / «Ver aprobación» link of a work item
 * (docs/contrato-tareas-aprobaciones.md §5): `https`, no user info, and
 * exactly the host (and port, if any) of the integration's catalog app,
 * compared without case. A subdomain, a trailing dot or another port is
 * another host; only an explicit `:443`, the https default, equals no port.
 *
 * The URL is checked as text, not through parse_url(), whose reading of
 * odd URLs (`https://a\@b`) differs from a browser's: only printable ASCII
 * without a backslash is accepted, and the authority is everything between
 * `https://` and the first `/`, `?` or `#`, as a browser reads it.
 */
final class WorkItemLink
{
    public const NOT_HTTPS = 'The url must use https.';

    public const USER_INFO = 'The url must not contain a user name or password.';

    public const WRONG_HOST = 'The url host must be the host of the app registered in the portal.';

    public const NO_APP = 'The integration has no catalog app with a url.';

    /**
     * Lowercase `host[:port]` of the catalog app URL; null without one.
     */
    public static function authorityOf(?string $appUrl): ?string
    {
        $host = $appUrl === null ? null : parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($appUrl, PHP_URL_PORT);

        return self::withoutDefaultPort(strtolower($host).($port === null ? '' : ':'.$port));
    }

    /**
     * Why the link may not be used, or null when it may.
     */
    public static function problem(string $url, ?string $allowedAuthority): ?string
    {
        if ($allowedAuthority === null) {
            return self::NO_APP;
        }

        if (preg_match('~\A[\x21-\x7E]+\z~', $url) !== 1 || str_contains($url, '\\')
            || preg_match('~\Ahttps://([^/?#]*)~i', $url, $match) !== 1) {
            return self::NOT_HTTPS;
        }

        $authority = self::withoutDefaultPort(strtolower($match[1]));

        if (str_contains($authority, '@')) {
            return self::USER_INFO;
        }

        return $authority === $allowedAuthority ? null : self::WRONG_HOST;
    }

    /**
     * `host:443` as `host`; any other port, or a malformed one, as written.
     */
    private static function withoutDefaultPort(string $authority): string
    {
        return str_ends_with($authority, ':443') ? substr($authority, 0, -4) : $authority;
    }
}
