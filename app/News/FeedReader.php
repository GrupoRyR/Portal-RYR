<?php

namespace App\News;

use Carbon\CarbonImmutable;

/**
 * Reads the headlines at an address (odd/tasks/observaciones.md O3): the
 * feed itself, or, when the address is a web page, the first feed the page
 * announces that reads (App\News\FeedDiscovery), at most MAX_CANDIDATES of
 * them. Every request goes through App\News\FeedFetcher (https only, no
 * private or reserved address, size and time limits) and every body through
 * App\News\FeedParser (no DOCTYPE, no entities).
 *
 * The failures say what really happened:
 * - `html_page` only for a page that announces no usable https feed;
 * - when the announced feeds fail, the failure of the last one tried (a
 *   timeout, `http_503`…; `unsupported_format` when it is a page too);
 * - a feed that fails for its own reason keeps its code, even when the
 *   site serves it as `text/html`, unless the body really looks like HTML
 *   (or is not RSS nor Atom and is served as a page).
 *
 * With a $deadline (Admin's save), the page and the feeds share it: no
 * request starts after it.
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
    public function read(string $url, int $limit, ?CarbonImmutable $deadline = null): FeedRead
    {
        $page = $this->fetcher->download($url, $deadline);

        try {
            return new FeedRead($url, $this->parser->parse($page->body, $limit, $page->url), false);
        } catch (FeedFailure $failure) {
            if (! self::isPage($failure, $page)) {
                throw $failure;
            }
        }

        $candidates = array_values(array_diff(FeedDiscovery::candidates($page->body, $page->url), [$url, $page->url]));

        if ($candidates === []) {
            throw new FeedFailure(FeedFailure::HTML_PAGE);
        }

        $last = null;

        foreach (array_slice($candidates, 0, self::MAX_CANDIDATES) as $candidate) {
            try {
                $feed = $this->fetcher->download($candidate, $deadline);

                return new FeedRead($candidate, $this->parser->parse($feed->body, $limit, $feed->url), true);
            } catch (FeedFailure $failure) {
                $last = $failure;

                if ($failure->errorCode === FeedFailure::TIMEOUT && $deadline !== null && ! CarbonImmutable::now()->lessThan($deadline)) {
                    break;
                }
            }
        }

        // An announced «feed» that is a page too is not a feed: never `html_page`,
        // which would tell Admin the typed address announces nothing.
        throw $last->errorCode === FeedFailure::HTML_PAGE
            ? new FeedFailure(FeedFailure::UNSUPPORTED_FORMAT)
            : $last;
    }

    /**
     * Whether the failure means the body is a web page: the parser said so
     * (it looks like HTML), or it is no feed format and the site serves it
     * as a page.
     */
    private static function isPage(FeedFailure $failure, FetchedPage $page): bool
    {
        return $failure->errorCode === FeedFailure::HTML_PAGE
            || ($failure->errorCode === FeedFailure::UNSUPPORTED_FORMAT && $page->isHtml());
    }
}
