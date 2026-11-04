<?php

namespace App\News;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;

/**
 * Reads the headlines of an RSS 2.0, RSS 1.0 (RDF) or Atom feed.
 *
 * Safety comes before parsing:
 * - a web page (HTML, {@see looksLikeHtml()}) is refused as `html_page`, so
 *   Admin can say it is a page and not a feed; it is never parsed;
 * - any `<!DOCTYPE` or `<!ENTITY` is refused before libxml sees the bytes,
 *   so neither external entities (XXE) nor entity expansion can happen;
 *   the parser also runs without entity substitution (no LIBXML_NOENT),
 *   without loading DTDs and without network access (LIBXML_NONET);
 * - UTF-16/UTF-32 documents (a BOM or NUL bytes) are refused, because a
 *   DOCTYPE could hide from the byte check in those encodings;
 * - only the title (plain text), the https link and the date are kept.
 *   Descriptions, content and any HTML are dropped.
 */
final class FeedParser
{
    public const TITLE_MAX = 300;

    /** Dates written without a zone are Bogotá time. */
    public const DEFAULT_TIMEZONE = 'America/Bogota';

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const RSS1 = 'http://purl.org/rss/1.0/';

    private const DC = 'http://purl.org/dc/elements/1.1/';

    private const XML = 'http://www.w3.org/XML/1998/namespace';

    /** RFC 822 zone names, plus Colombia (COT). */
    private const ZONES = [
        'Z' => '+0000',
        'UT' => '+0000',
        'UTC' => '+0000',
        'GMT' => '+0000',
        'COT' => '-0500',
        'EST' => '-0500',
        'EDT' => '-0400',
        'CST' => '-0600',
        'CDT' => '-0500',
        'MST' => '-0700',
        'MDT' => '-0600',
        'PST' => '-0800',
        'PDT' => '-0700',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Relative item links resolve against $baseUrl (the feed URL) and any
     * `xml:base`; the result must still be a safe https link.
     *
     * @return non-empty-list<FeedEntry> In feed order, at most $limit.
     *
     * @throws FeedFailure html_page, doctype, invalid_xml, unsupported_format or no_entries
     */
    public function parse(string $xml, int $limit = 100, ?string $baseUrl = null): array
    {
        $document = $this->load($xml);
        $root = $document->documentElement;

        [$entries, $atom] = match (true) {
            $root?->localName === 'rss' && $root->namespaceURI === null => [$this->rssItems($root), false],
            $root?->localName === 'RDF' => [$this->children($root, 'item', [self::RSS1]), false],
            $root?->localName === 'feed' && $root->namespaceURI === self::ATOM => [$this->children($root, 'entry', [self::ATOM]), true],
            default => throw new FeedFailure(FeedFailure::UNSUPPORTED_FORMAT),
        };

        $result = [];

        foreach ($entries as $entry) {
            if (count($result) >= $limit) {
                break;
            }

            $parsed = $atom ? $this->atomEntry($entry, $baseUrl) : $this->rssItem($entry, $baseUrl);

            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }

        // A feed that parses but has nothing to show is not a success.
        if ($result === []) {
            throw new FeedFailure(FeedFailure::NO_ENTRIES);
        }

        return $result;
    }

    /**
     * Whether the body is a web page and not a feed: after a UTF-8 BOM,
     * whitespace and comments it starts with `<!doctype html` or `<html`.
     * Only the first bytes are looked at. A document that starts with an
     * XML declaration is XML, so a DOCTYPE in it is still refused as unsafe.
     */
    public static function looksLikeHtml(string $body): bool
    {
        $start = substr($body, 0, 4096);

        if (str_starts_with($start, "\xEF\xBB\xBF")) {
            $start = substr($start, 3);
        }

        $start = (string) preg_replace('/\A(?:\s+|<!--.*?-->)*/s', '', $start);

        return preg_match('/\A<(?:!doctype\s+html|html)\b/i', $start) === 1;
    }

    private function load(string $xml): DOMDocument
    {
        if (str_starts_with($xml, "\xFE\xFF") || str_starts_with($xml, "\xFF\xFE") || str_contains($xml, "\0")) {
            throw new FeedFailure(FeedFailure::INVALID_XML);
        }

        if (self::looksLikeHtml($xml)) {
            throw new FeedFailure(FeedFailure::HTML_PAGE);
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new FeedFailure(FeedFailure::DOCTYPE);
        }

        if (trim($xml) === '') {
            throw new FeedFailure(FeedFailure::INVALID_XML);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new FeedFailure(FeedFailure::INVALID_XML);
        }

        // Belt and braces: the byte check above already refused it.
        if ($document->doctype !== null) {
            throw new FeedFailure(FeedFailure::DOCTYPE);
        }

        return $document;
    }

    /**
     * @return list<DOMElement>
     */
    private function rssItems(DOMElement $rss): array
    {
        $channel = $this->child($rss, 'channel', [null]);

        return $channel === null ? [] : $this->children($channel, 'item', [null]);
    }

    private function rssItem(DOMElement $item, ?string $base): ?FeedEntry
    {
        $plain = [null, self::RSS1];
        $linkElement = $this->child($item, 'link', $plain);
        $link = $linkElement === null ? null : RelativeUrl::resolve($this->baseFor($linkElement, $base), $linkElement->textContent);
        $guid = trim((string) $this->child($item, 'guid', [null])?->textContent);
        $date = $this->child($item, 'pubDate', [null]) ?? $this->child($item, 'date', [self::DC]);

        return $this->entry($this->child($item, 'title', $plain)?->textContent, $link, $date?->textContent, $guid);
    }

    private function atomEntry(DOMElement $entry, ?string $base): ?FeedEntry
    {
        $chosen = null;

        foreach ($this->children($entry, 'link', [self::ATOM]) as $candidate) {
            $rel = $candidate->getAttribute('rel');

            if ($rel === 'alternate' || ($rel === '' && $chosen === null)) {
                $chosen = $candidate;

                if ($rel === 'alternate') {
                    break;
                }
            }
        }

        $link = $chosen === null ? null : RelativeUrl::resolve($this->baseFor($chosen, $base), $chosen->getAttribute('href'));
        $date = $this->child($entry, 'published', [self::ATOM]) ?? $this->child($entry, 'updated', [self::ATOM]);
        $id = trim((string) $this->child($entry, 'id', [self::ATOM])?->textContent);

        return $this->entry($this->child($entry, 'title', [self::ATOM])?->textContent, $link, $date?->textContent, $id);
    }

    /**
     * The base URL of an element: the feed URL, then every `xml:base` from
     * the root down to the element, each resolved against the previous one.
     */
    private function baseFor(DOMElement $element, ?string $base): ?string
    {
        $chain = [];

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $xmlBase = $node->getAttributeNS(self::XML, 'base');

            if ($xmlBase !== '') {
                $chain[] = $xmlBase;
            }
        }

        foreach (array_reverse($chain) as $xmlBase) {
            $base = RelativeUrl::resolve($base, $xmlBase);
        }

        return $base;
    }

    private function entry(?string $title, ?string $link, ?string $date, string $guid): ?FeedEntry
    {
        $title = self::plainText((string) $title);

        if ($title === '' || $link === null || ! FeedUrlPolicy::isSafeLink($link)) {
            return null;
        }

        return new FeedEntry($title, $link, self::date($date), $guid !== '' ? $guid : $link);
    }

    /**
     * Plain text of a title: script and style blocks removed, tag-like
     * sequences (`<b>`, `</a>`, `<img …>`, comments) stripped and entities
     * decoded until nothing changes (titles are often escaped twice),
     * control characters dropped, whitespace collapsed, cut to TITLE_MAX
     * characters. A `<` that does not start a tag («Tasa <5%») is kept.
     */
    public static function plainText(string $text): string
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $before = $text;
            $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? '';
            $text = preg_replace('#<(script|style)\b.*\z#is', ' ', $text) ?? '';
            $text = self::stripTags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($text === $before) {
                break;
            }
        }

        $text = self::stripTags($text);
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, self::TITLE_MAX);
    }

    private static function stripTags(string $text): string
    {
        return preg_replace(['#<!--.*?(?:-->|\z)#s', '#</?[a-z][^<>]*>#i'], ' ', $text) ?? '';
    }

    /**
     * An RFC 822 (RSS) or ISO 8601 (Atom, Dublin Core) date; anything else
     * is null rather than guessed.
     * - The weekday is ignored: PHP reads a wrong one («Fri, 02 Nov 2026»,
     *   a Monday) as "next Friday" and moves the date.
     * - Zones: ±HHMM, ±HH:MM, ±HH, Z and the RFC 822 names (UT, GMT, EST…)
     *   plus COT; an unknown name gives no date.
     * - Without a zone the time is Bogotá time (DEFAULT_TIMEZONE).
     */
    private static function date(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        $rfc822 = '/\A(?:[A-Za-z]{2,9}\.?,?\s*)?(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4}|\d{2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\s*([+-]\d{2}(?::?\d{2})?|[A-Za-z]{1,5}))?\z/';
        $iso8601 = '/\A(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)?)?\z/i';

        if (preg_match($rfc822, $value, $m) === 1) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? 0;

            return self::build($year, $month, (int) $m[1], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0), $m[7] ?? '');
        }

        if (preg_match($iso8601, $value, $m) === 1) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), $m[7] ?? '');
        }

        return null;
    }

    private static function build(int $year, int $month, int $day, int $hour, int $minute, int $second, string $zone): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $timezone = $zone === '' ? self::DEFAULT_TIMEZONE : self::offset($zone);

        if ($timezone === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j G:i:s',
            sprintf('%d-%d-%d %d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            new DateTimeZone($timezone),
        );

        return $date === false ? null : CarbonImmutable::instance($date);
    }

    /**
     * «+0000», «-0500»… for a zone written in the feed; null when unknown.
     */
    private static function offset(string $zone): ?string
    {
        $named = self::ZONES[strtoupper($zone)] ?? null;

        if ($named !== null) {
            return $named;
        }

        if (preg_match('/\A([+-])(\d{2}):?(\d{2})?\z/', $zone, $m) !== 1 || (int) $m[2] > 14 || (int) ($m[3] ?? 0) > 59) {
            return null;
        }

        return $m[1].$m[2].(($m[3] ?? '') === '' ? '00' : $m[3]);
    }

    /**
     * @param  list<?string>  $namespaces
     */
    private function child(DOMElement $parent, string $name, array $namespaces): ?DOMElement
    {
        return $this->children($parent, $name, $namespaces, 1)[0] ?? null;
    }

    /**
     * Direct child elements with this local name in one of the namespaces
     * (null is "no namespace").
     *
     * @param  list<?string>  $namespaces
     * @return list<DOMElement>
     */
    private function children(DOMElement $parent, string $name, array $namespaces, ?int $limit = null): array
    {
        $found = [];

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name && in_array($node->namespaceURI, $namespaces, true)) {
                $found[] = $node;

                if ($limit !== null && count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }
}
