<?php

namespace App\News;

/**
 * What App\News\FeedFetcher::download() brought back: the (decoded) body,
 * the Content-Type the site declared and the address it finally came from
 * after the redirects, which relative links resolve against.
 */
final readonly class FetchedPage
{
    public function __construct(
        public string $body,
        public string $contentType,
        public string $url,
    ) {}

    /**
     * Declared as an HTML page (`text/html` or `application/xhtml+xml`), or
     * shaped like one (App\News\FeedParser::looksLikeHtml()).
     */
    public function isHtml(): bool
    {
        $type = strtolower(trim(explode(';', $this->contentType)[0]));

        return in_array($type, ['text/html', 'application/xhtml+xml'], true) || FeedParser::looksLikeHtml($this->body);
    }
}
