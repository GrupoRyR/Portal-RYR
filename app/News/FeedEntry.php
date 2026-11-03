<?php

namespace App\News;

use Carbon\CarbonImmutable;

/**
 * One headline of a feed, already cleaned by App\News\FeedParser: plain
 * text title, safe https link, the date when the feed has a readable one,
 * and the identifier (guid, Atom id, or else the link) that deduplicates it.
 */
final readonly class FeedEntry
{
    public function __construct(
        public string $title,
        public string $link,
        public ?CarbonImmutable $publishedAt,
        public string $guid,
    ) {}
}
