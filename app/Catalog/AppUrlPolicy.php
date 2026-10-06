<?php

namespace App\Catalog;

use App\Settings\PortalSettings;

/**
 * Decides which URLs a web app may point at: `https://` only, on a host
 * from the allowed list, with no user info and no explicit port.
 *
 * Host patterns:
 * - A plain domain (`segurex.com.co`) matches itself and its subdomains
 *   (`pedidos.segurex.com.co`), always on a dot boundary, so lookalikes
 *   such as `evilsegurex.com.co` or `segurex.com.co.evil.io` are rejected.
 * - A pattern with `*` (`sgx-*.vercel.app`) matches exactly, where `*`
 *   stands for one or more characters of a single DNS label (no dots).
 */
final readonly class AppUrlPolicy
{
    /** @var list<string> */
    private array $patterns;

    /**
     * @param  iterable<string>  $patterns
     */
    public function __construct(iterable $patterns)
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));

            if ($pattern !== '') {
                $normalized[] = $pattern;
            }
        }

        $this->patterns = $normalized;
    }

    /**
     * The policy with the hosts of Configuración (App\Settings\PortalSettings,
     * config portal.allowed_app_hosts until a super admin saves).
     */
    public static function current(): self
    {
        return new self(app(PortalSettings::class)->allowedAppHosts());
    }

    /**
     * Whether a lowercase host pattern may be added to the list: two or more
     * DNS labels, and at most one `*` inside a label that also has a fixed
     * part (`sgx-*`), never in the last two labels, so a pattern cannot
     * open a whole domain or top-level domain to anyone's apps.
     */
    public static function isValidPattern(string $pattern): bool
    {
        if ($pattern === '' || strlen($pattern) > 253 || substr_count($pattern, '*') > 1) {
            return false;
        }

        $labels = explode('.', $pattern);

        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $index => $label) {
            $wildcard = str_contains($label, '*');

            if ($wildcard && ($label === '*' || $index >= count($labels) - 2)) {
                return false;
            }

            $plain = str_replace('*', 'x', $label);

            if (strlen($plain) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $plain) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function allows(?string $url): bool
    {
        $host = $this->hostOf($url);

        if ($host === null) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if ($this->hostMatches($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowercase host of a well-formed `https://` URL, or null.
     */
    private function hostOf(?string $url): ?string
    {
        if ($url === null || $url === '' || $url !== trim($url) || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! isset($parts['host'])) {
            return null;
        }

        // The authority must be exactly the host: parse_url() is lenient,
        // so compare against the raw text after "https://".
        $afterScheme = substr($url, strlen('https://'));
        $authority = strtolower((string) strtok($afterScheme, '/?#'));
        $host = strtolower($parts['host']);

        if ($authority !== $host) {
            return null;
        }

        // Plain DNS names only: letters, digits and hyphens per label, no
        // empty labels (this also rejects a trailing dot).
        foreach (explode('.', $host) as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $label) !== 1) {
                return null;
            }
        }

        return $host;
    }

    private function hostMatches(string $host, string $pattern): bool
    {
        if (! str_contains($pattern, '*')) {
            return $host === $pattern || str_ends_with($host, '.'.$pattern);
        }

        $regex = '/\A'.str_replace('\*', '[a-z0-9-]+', preg_quote($pattern, '/')).'\z/';

        return preg_match($regex, $host) === 1;
    }
}
