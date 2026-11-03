<?php

namespace App\News;

/**
 * Which URLs the news fetcher may request, and which item links it keeps.
 *
 * A feed URL is `https://` on the default port (443, never written), with
 * no user info, on a DNS name with two or more labels and a letters-only
 * top-level domain. That rules out every IP literal, including the decimal,
 * hexadecimal and IPv6 forms, so each host goes through the DNS check of
 * App\News\FeedFetcher. App\Catalog\AppUrlPolicy has the same shape but
 * only accepts hosts from the catalog allowlist, so it does not fit here.
 *
 * An item link is only shown, never fetched: it must be an absolute
 * `https://` URL without user info or whitespace.
 */
final class FeedUrlPolicy
{
    public const MAX_URL = 2048;

    /** Top-level names that never reach the public internet. */
    private const PRIVATE_TLDS = ['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'test', 'invalid', 'onion', 'arpa'];

    /**
     * The lowercase host of an acceptable feed URL, or null.
     */
    public static function hostOf(?string $url): ?string
    {
        if (! self::isCleanAbsoluteHttps($url)) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! isset($parts['host'])) {
            return null;
        }

        // The authority must be exactly the host: parse_url() is lenient,
        // so compare against the raw text after "https://".
        $authority = strtolower((string) strtok(substr($url, strlen('https://')), '/?#'));
        $host = strtolower($parts['host']);

        if ($authority !== $host || strlen($host) > 253) {
            return null;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/', $label) !== 1) {
                return null;
            }
        }

        $tld = end($labels);

        if (preg_match('/\A[a-z]{2,63}\z/', $tld) !== 1 || in_array($tld, self::PRIVATE_TLDS, true)) {
            return null;
        }

        return $host;
    }

    /**
     * Whether an item link may be stored and shown.
     */
    public static function isSafeLink(?string $link): bool
    {
        if (! self::isCleanAbsoluteHttps($link)) {
            return false;
        }

        $parts = parse_url($link);

        return $parts !== false
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && isset($parts['host'])
            && $parts['host'] !== '';
    }

    /**
     * An `https://` URL of at most MAX_URL characters, with no whitespace,
     * control characters or backslashes anywhere.
     */
    private static function isCleanAbsoluteHttps(?string $url): bool
    {
        return $url !== null
            && $url !== ''
            && strlen($url) <= self::MAX_URL
            && preg_match('/[\x00-\x20\x7F\\\\]/', $url) !== 1
            && strncasecmp($url, 'https://', 8) === 0;
    }
}
