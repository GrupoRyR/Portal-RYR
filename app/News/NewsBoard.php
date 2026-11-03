<?php

namespace App\News;

use App\Models\NewsItem;
use App\Models\NewsSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The «Noticias» of one company: the newest `news.board_items_per_tab`
 * headlines of each tab, across the company's active sources, newest
 * first. One query: ROW_NUMBER() per tab (MySQL 8 and SQLite 3.25+).
 */
class NewsBoard
{
    /**
     * @return array<string, list<NewsHeadline>> Every tab of NewsSource::TABS, in order.
     */
    public function forCompany(int $companyId): array
    {
        $limit = (int) config('news.board_items_per_tab');

        $ranked = NewsItem::query()
            ->join('news_sources', 'news_sources.id', '=', 'news_items.news_source_id')
            ->where('news_sources.company_id', $companyId)
            ->where('news_sources.active', true)
            ->select([
                'news_items.id',
                'news_items.title',
                'news_items.link',
                'news_items.published_at',
                'news_sources.tab',
                'news_sources.name as source_name',
            ])
            ->selectRaw('row_number() over (partition by news_sources.tab order by news_items.published_at desc, news_items.id desc) as position')
            ->toBase();

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('position', '<=', $limit)
            ->orderBy('tab')
            ->orderBy('position')
            ->get();

        $board = array_fill_keys(array_keys(NewsSource::TABS), []);
        $timezone = (string) config('app.timezone');

        foreach ($rows as $row) {
            if (! array_key_exists($row->tab, $board)) {
                continue;
            }

            $board[$row->tab][] = new NewsHeadline(
                (int) $row->id,
                (string) $row->title,
                (string) $row->link,
                CarbonImmutable::parse($row->published_at, $timezone),
                (string) $row->source_name,
            );
        }

        return $board;
    }
}
