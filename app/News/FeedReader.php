<?php

namespace App\News;

/**
 * Reads the headlines at an address (odd/tasks/observaciones.md O3): the
 * feed itself, or, when the address is a web page, the first feed the page
 * announces that reads (App\News\FeedDiscovery), at most MAX_CANDIDATES of
 * them. Every request goes through App\News\FeedFetcher (https only, no
 * private or reserved address, size and time limits) and every body through
 * App\News\FeedParser (no DOCTYPE, no entities). A page without a usable
 * feed is the failure `html_page`.
 */
class FeedReader
{
    /** Announced feeds tried per page. */
    public const MAX_CANDIDATES = 2;

    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly FeedParser $parser,
    ) {}

    /**
     * @throws FeedFailure
     */
    public function read(string $url, int $limit): FeedRead
    {
        $page = $this->fetcher->download($url);

        try {
            return new FeedRead($url, $this->parser->parse($page->body, $limit, $page->url), false);
        } catch (FeedFailure $failure) {
            // A feed with a real problem keeps its own code.
            if ($failure->errorCode !== FeedFailure::HTML_PAGE && ! $page->isHtml()) {
                throw $failure;
            }
        }

        $candidates = array_values(array_diff(FeedDiscovery::candidates($page->body, $page->url), [$url, $page->url]));

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATES) as $candidate) {
            try {
                $feed = $this->fetcher->download($candidate);

                return new FeedRead($candidate, $this->parser->parse($feed->body, $limit, $feed->url), true);
            } catch (FeedFailure) {
                // Not a usable feed: the next one, if any.
            }
        }

        throw new FeedFailure(FeedFailure::HTML_PAGE);
    }
}
