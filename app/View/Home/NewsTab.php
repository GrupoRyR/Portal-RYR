<?php

namespace App\View\Home;

use App\Models\NewsSource;
use App\News\NewsHeadline;

/**
 * One tab of the «Noticias» block of the home (App\News\NewsBoard): its
 * headlines, newest first, and whether it is the one shown. Without
 * JavaScript, each tab is a link to `/?noticias={key}#noticias`.
 */
final readonly class NewsTab
{
    /**
     * @param  list<NewsHeadline>  $headlines
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $headlines,
        public bool $selected,
    ) {}

    /**
     * The tabs that have headlines, in NewsSource::TABS order; the one
     * named in $requested is selected when it has headlines, the first
     * one otherwise.
     *
     * @param  array<string, list<NewsHeadline>>  $board
     * @return list<self>
     */
    public static function fromBoard(array $board, ?string $requested): array
    {
        $keys = array_values(array_filter(
            array_keys(NewsSource::TABS),
            fn (string $key) => ($board[$key] ?? []) !== [],
        ));

        $selected = in_array($requested, $keys, true) ? $requested : ($keys[0] ?? null);

        return array_map(fn (string $key) => new self(
            $key,
            NewsSource::TABS[$key],
            $board[$key],
            $key === $selected,
        ), $keys);
    }

    public function href(): string
    {
        return route('home', ['noticias' => $this->key]).'#noticias';
    }

    /** «3 nov» beside the source. */
    public static function dateOf(NewsHeadline $headline): string
    {
        return str_replace('.', '', $headline->publishedAt->locale('es')->isoFormat('D MMM'));
    }
}
