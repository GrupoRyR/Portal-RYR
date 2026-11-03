<?php

namespace App\News;

/**
 * Resolves a URL reference against a base URL (RFC 3986 §5.2), for item
 * links and Atom `xml:base`. The result is only a candidate: callers still
 * check it with App\News\FeedUrlPolicy::isSafeLink(). User info of the base
 * is never carried over.
 */
final class RelativeUrl
{
    /**
     * The absolute URL, or null when the reference is empty or relative
     * without a usable absolute base.
     */
    public static function resolve(?string $base, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $reference) === 1) {
            return $reference;
        }

        $parts = $base === null ? false : parse_url($base);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (str_starts_with($reference, '//')) {
            return "{$scheme}:{$reference}";
        }

        $origin = "{$scheme}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
        $basePath = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];

        if (str_starts_with($reference, '?')) {
            return $origin.$basePath.$reference;
        }

        if (str_starts_with($reference, '#')) {
            return $origin.$basePath.(isset($parts['query']) ? "?{$parts['query']}" : '').$reference;
        }

        $cut = strcspn($reference, '?#');
        $path = substr($reference, 0, $cut);
        $suffix = substr($reference, $cut);

        $merged = str_starts_with($path, '/')
            ? $path
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1).$path;

        return $origin.self::removeDotSegments($merged).$suffix;
    }

    /**
     * RFC 3986 §5.2.4.
     */
    private static function removeDotSegments(string $input): string
    {
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = '/'.substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = '/'.substr($input, 4);
                $output = substr($output, 0, (int) strrpos($output, '/'));
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $next = strpos($input, '/', 1);
                $output .= $next === false ? $input : substr($input, 0, $next);
                $input = $next === false ? '' : substr($input, $next);
            }
        }

        return $output === '' ? '/' : $output;
    }
}
