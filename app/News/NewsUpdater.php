<?php

namespace App\News;

use App\Models\NewsItem;
use App\Models\NewsSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads every active news source (`portal:fetch-news`, hourly) and keeps
 * its newest headlines:
 * - one source at a time; a failure is stored on that source as a short
 *   code (App\News\FeedFailure) and the run goes on with the next one;
 *   its previous items stay;
 * - items are matched by `guid_hash`, so a headline seen again is updated,
 *   not duplicated;
 * - a missing date counts as the time of the fetch, and a date in the
 *   future is capped to it, so a feed cannot pin itself to the top;
 * - the newest `news.items_per_source` are kept per source, and items older
 *   than `news.retention_days` are deleted everywhere.
 */
class NewsUpdater
{
    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly FeedParser $parser,
    ) {}

    /**
     * @return array{fetched: int, failed: int}
     */
    public function run(): array
    {
        $result = ['fetched' => 0, 'failed' => 0];

        NewsSource::query()
            ->where('active', true)
            ->orderBy('id')
            ->each(function (NewsSource $source) use (&$result) {
                $this->update($source) ? $result['fetched']++ : $result['failed']++;
            });

        $this->prune();

        return $result;
    }

    /**
     * Fetches one source and records the attempt; true when it was read.
     */
    public function update(NewsSource $source): bool
    {
        $now = CarbonImmutable::now();
        $code = null;

        try {
            $entries = $this->parser->parse($this->fetcher->fetch($source->url), (int) config('news.max_entries'));
            $this->store($source, $entries, $now);
        } catch (FeedFailure $failure) {
            $code = $failure->errorCode;
        } catch (Throwable $exception) {
            // Only the class: a message could carry part of a response.
            Log::warning('News source could not be read.', ['news_source_id' => $source->id, 'exception' => $exception::class]);
            $code = FeedFailure::UNEXPECTED;
        }

        $source->forceFill(['last_fetched_at' => $now, 'last_error_code' => $code])->saveQuietly();

        return $code === null;
    }

    /**
     * @param  list<FeedEntry>  $entries
     */
    private function store(NewsSource $source, array $entries, CarbonImmutable $now): void
    {
        $cutoff = $now->subDays((int) config('news.retention_days'));
        // Raw rows skip the casts: write them as Eloquent would, in the app timezone.
        $stamp = fn (CarbonImmutable $time) => $time->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($entries as $entry) {
            $hash = hash('sha256', $entry->guid);
            $published = $entry->publishedAt === null || $entry->publishedAt->greaterThan($now) ? $now : $entry->publishedAt;

            if (isset($rows[$hash]) || $published->lessThan($cutoff)) {
                continue;
            }

            $rows[$hash] = [
                'news_source_id' => $source->id,
                'title' => $entry->title,
                'link' => $entry->link,
                'published_at' => $stamp($published),
                'guid_hash' => $hash,
                'created_at' => $stamp($now),
                'updated_at' => $stamp($now),
            ];
        }

        $keep = (int) config('news.items_per_source');
        $rows = collect($rows)->sortByDesc('published_at')->take($keep)->values()->all();

        DB::transaction(function () use ($source, $rows, $keep) {
            if ($rows !== []) {
                NewsItem::query()->upsert($rows, ['news_source_id', 'guid_hash'], ['title', 'link', 'published_at', 'updated_at']);
            }

            $newest = $source->items()->orderByDesc('published_at')->orderByDesc('id')->limit($keep)->pluck('id');
            $source->items()->whereNotIn('id', $newest)->delete();
        });
    }

    private function prune(): void
    {
        NewsItem::query()
            ->where('published_at', '<', CarbonImmutable::now()->subDays((int) config('news.retention_days')))
            ->delete();
    }
}
