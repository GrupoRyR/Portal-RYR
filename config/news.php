<?php

/*
 * Noticias: RSS/Atom headlines per company and tab (App\News, Admin ›
 * Noticias, `portal:fetch-news`). The limits are the SSRF and size
 * boundary of App\News\FeedFetcher; raise them only with a reason.
 */
return [
    // Seconds for the whole request, and for the TCP/TLS connection.
    'timeout' => 10,
    'connect_timeout' => 5,

    // A feed body larger than this is refused (1 MB).
    'max_bytes' => 1_048_576,

    // Redirects followed by the fetcher, each checked again like the first URL.
    'max_redirects' => 3,

    // Entries read from one feed, in feed order.
    'max_entries' => 100,

    // Items kept per source (the newest), and their maximum age in days.
    'items_per_source' => 30,
    'retention_days' => 60,

    // Items per tab returned by App\News\NewsBoard (the home «Noticias»).
    'board_items_per_tab' => 5,

    'user_agent' => 'PortalRYR-Noticias/1.0 (+https://apps.segurexlatam.net)',
];
