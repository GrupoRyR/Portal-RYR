<?php

namespace App\News;

use Carbon\CarbonImmutable;

/**
 * One headline of the «Noticias» board (App\News\NewsBoard): plain text
 * title, https link, date and the name of its source. Escape it like any
 * other text when rendering; the link always opens outside the portal.
 */
final readonly class NewsHeadline
{
    public function __construct(
        public int $id,
        public string $title,
        public string $link,
        public CarbonImmutable $publishedAt,
        public string $source,
    ) {}
}
