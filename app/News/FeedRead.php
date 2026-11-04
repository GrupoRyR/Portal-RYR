<?php

namespace App\News;

/**
 * The headlines read by App\News\FeedReader and the feed address they came
 * from: the one asked for, or the one the page announced (`discovered`).
 */
final readonly class FeedRead
{
    /**
     * @param  non-empty-list<FeedEntry>  $entries
     */
    public function __construct(
        public string $url,
        public array $entries,
        public bool $discovered,
    ) {}
}
