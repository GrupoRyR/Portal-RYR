<?php

namespace App\News;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;

/**
 * Reads the headlines of an RSS 2.0, RSS 1.0 (RDF) or Atom feed.
 *
 * Safety comes before parsing:
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

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const RSS1 = 'http://purl.org/rss/1.0/';

    private const DC = 'http://purl.org/dc/elements/1.1/';

    /**
     * @return list<FeedEntry> In feed order, at most $limit.
     *
     * @throws FeedFailure doctype, invalid_xml or unsupported_format
     */
    public function parse(string $xml, int $limit = 100): array
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

            $parsed = $atom ? $this->atomEntry($entry) : $this->rssItem($entry);

            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    private function load(string $xml): DOMDocument
    {
        if (str_starts_with($xml, "\xFE\xFF") || str_starts_with($xml, "\xFF\xFE") || str_contains($xml, "\0")) {
            throw new FeedFailure(FeedFailure::INVALID_XML);
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

    private function rssItem(DOMElement $item): ?FeedEntry
    {
        $plain = [null, self::RSS1];
        $link = trim((string) $this->child($item, 'link', $plain)?->textContent);
        $guid = trim((string) $this->child($item, 'guid', [null])?->textContent);
        $date = $this->child($item, 'pubDate', [null]) ?? $this->child($item, 'date', [self::DC]);

        return $this->entry($this->child($item, 'title', $plain)?->textContent, $link, $date?->textContent, $guid);
    }

    private function atomEntry(DOMElement $entry): ?FeedEntry
    {
        $link = null;

        foreach ($this->children($entry, 'link', [self::ATOM]) as $candidate) {
            $rel = $candidate->getAttribute('rel');

            if ($rel === 'alternate' || ($rel === '' && $link === null)) {
                $link = $candidate->getAttribute('href');

                if ($rel === 'alternate') {
                    break;
                }
            }
        }

        $date = $this->child($entry, 'published', [self::ATOM]) ?? $this->child($entry, 'updated', [self::ATOM]);
        $id = trim((string) $this->child($entry, 'id', [self::ATOM])?->textContent);

        return $this->entry($this->child($entry, 'title', [self::ATOM])?->textContent, trim((string) $link), $date?->textContent, $id);
    }

    private function entry(?string $title, string $link, ?string $date, string $guid): ?FeedEntry
    {
        $title = self::plainText((string) $title);

        if ($title === '' || ! FeedUrlPolicy::isSafeLink($link)) {
            return null;
        }

        return new FeedEntry($title, $link, self::date($date), $guid !== '' ? $guid : $link);
    }

    /**
     * Plain text of a title: script and style blocks removed, tags stripped
     * and entities decoded until nothing changes (titles are often escaped
     * twice), control characters dropped, whitespace collapsed, cut to
     * TITLE_MAX characters.
     */
    public static function plainText(string $text): string
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $before = $text;
            $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text) ?? '';
            $text = preg_replace('#<(script|style)\b.*\z#is', ' ', $text) ?? '';
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($text === $before) {
                break;
            }
        }

        $text = strip_tags($text);
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}\p{Z}\s]+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, self::TITLE_MAX);
    }

    /**
     * An RFC 822 (RSS) or ISO 8601 (Atom, Dublin Core) date; anything else
     * is null rather than guessed.
     */
    private static function date(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        $rfc822 = '/\A(?:[A-Za-z]{3},\s*)?\d{1,2}\s+[A-Za-z]{3}\s+\d{2,4}\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*(?:[+-]\d{4}|[A-Za-z]{1,5}))?\z/';
        $iso8601 = '/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/i';

        if (preg_match($rfc822, $value) !== 1 && preg_match($iso8601, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::instance(new DateTimeImmutable($value, new DateTimeZone('UTC')));
        } catch (Exception) {
            return null;
        }
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
