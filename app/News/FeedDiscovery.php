<?php

namespace App\News;

/**
 * RSS/Atom autodiscovery (odd/tasks/observaciones.md O3): the feeds a web
 * page announces in its `<head>` with
 * `<link rel="alternate" type="application/rss+xml|application/atom+xml" href="…">`.
 *
 * The page is never parsed as a document: a bounded regex reads the
 * `<link>` tags of its first MAX_BYTES (up to `</head>`), so nothing is
 * loaded, expanded or executed. Every href is resolved against the page
 * address and must pass App\News\FeedUrlPolicy (https, no user, no port, a
 * public DNS name); the caller still fetches it through App\News\FeedFetcher.
 */
final class FeedDiscovery
{
    /** Bytes of the page that are looked at. */
    public const MAX_BYTES = 262144;

    private const TYPES = ['application/rss+xml', 'application/atom+xml'];

    /**
     * The announced feed addresses, absolute and safe, in page order, each
     * once.
     *
     * @return list<string>
     */
    public static function candidates(string $html, string $pageUrl): array
    {
        $head = substr($html, 0, self::MAX_BYTES);

        if (($end = stripos($head, '</head')) !== false) {
            $head = substr($head, 0, $end);
        }

        // A commented-out link is not announced.
        $head = (string) preg_replace('/<!--.*?(?:-->|\z)/s', '', $head);

        if (preg_match_all('/<link\b([^<>]*)>/i', $head, $tags) === false) {
            return [];
        }

        $found = [];

        foreach ($tags[1] as $text) {
            $attributes = self::attributes($text);
            $rel = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? ''))) ?: [];
            $type = strtolower(trim(explode(';', $attributes['type'] ?? '')[0]));

            if (! in_array('alternate', $rel, true) || ! in_array($type, self::TYPES, true)) {
                continue;
            }

            $href = trim(html_entity_decode($attributes['href'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = $href === '' ? null : RelativeUrl::resolve($pageUrl, $href);

            if ($url === null || strlen($url) > FeedUrlPolicy::MAX_URL || FeedUrlPolicy::hostOf($url) === null || in_array($url, $found, true)) {
                continue;
            }

            $found[] = $url;
        }

        return $found;
    }

    /**
     * The attributes of one tag, lowercase names, the first of each name.
     *
     * @return array<string, string>
     */
    private static function attributes(string $text): array
    {
        preg_match_all(
            '/([a-z][a-z0-9:_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i',
            $text,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower((string) $match[1])] ??= (string) ($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $attributes;
    }
}
