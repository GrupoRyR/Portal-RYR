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
 * - a source saved with a web page instead of a feed is read through the
 *   feed the page announces (App\News\FeedReader); its URL then becomes
 *   that feed's, audited as a change by «Sistema», unless another source
 *   of the same company and tab already has it (odd/tasks/observaciones.md O3);
 * - if an admin changed the URL (or deleted the source) while it was being
 *   read, the result belongs to the old URL and is discarded;
 * - items are matched by `guid_hash`, so a headline seen again is updated,
 *   not duplicated;
 * - an item without a date, or with a date in the future, is dated when it
 *   is first seen and keeps that date, so it neither stays on top forever
 *   nor escapes the retention;
 * - the newest `news.items_per_source` are kept per source, and items older
 *   than `news.retention_days` are deleted everywhere.
 */
class NewsUpdater
{
    public function __construct(private readonly FeedReader $reader) {}

    /**
     * @return array{fetched: int, failed: int}
     */
    public function run(): array
    {
        $result = ['fetched' => 0, 'failed' => 0];

        NewsSource::query()
            ->where('active', true)
            ->lazyById(100)
            ->each(function (NewsSource $source) use (&$result) {
                match ($this->update($source)) {
                    true => $result['fetched']++,
                    false => $result['failed']++,
                    null => null,
                };
            });

        $this->prune();

        return $result;
    }

    /**
     * Fetches one source and records the attempt: true when it was read,
     * false when it failed, null when the source changed meanwhile and the
     * result was discarded.
     */
    public function update(NewsSource $source): ?bool
    {
        $now = CarbonImmutable::now();
        $url = $source->url;
        $feedUrl = $url;
        $entries = [];
        $code = null;

        try {
            $read = $this->reader->read($url, (int) config('news.max_entries'));
            $entries = $read->entries;
            $feedUrl = $read->url;
        } catch (FeedFailure $failure) {
            $code = $failure->errorCode;
        } catch (Throwable $exception) {
            $code = $this->unexpected($source, $exception);
        }

        try {
            return DB::transaction(fn () => $this->record($source->id, $url, $entries, $code, $now, $feedUrl));
        } catch (Throwable $exception) {
            $code = $this->unexpected($source, $exception);
            $this->bookkeeping($source->id, $url, $code, $now);

            return false;
        }
    }

    /**
     * Writes the items and the outcome, unless the source is gone or its URL
     * is no longer the one that was read. A feed found on the page
     * ($feedUrl) replaces the URL first.
     *
     * @param  list<FeedEntry>  $entries
     */
    private function record(int $sourceId, string $url, array $entries, ?string $code, CarbonImmutable $now, string $feedUrl): ?bool
    {
        $current = NewsSource::query()->whereKey($sourceId)->lockForUpdate()->first();

        if ($current === null || $current->url !== $url) {
            return null;
        }

        if ($code === null && $feedUrl !== $url) {
            if ($this->taken($current, $feedUrl)) {
                $code = FeedFailure::HTML_PAGE;
            } else {
                // Audited by the model (log `news`), without a causer: «Sistema».
                $current->update(['url' => $feedUrl]);
                $url = $feedUrl;
            }
        }

        if ($code === null) {
            $this->store($sourceId, $entries, $now);
        }

        $this->bookkeeping($sourceId, $url, $code, $now);

        return $code === null;
    }

    /**
     * The time of the attempt and its error code, only while the URL is the
     * one that was read. Not audited: it is not an admin change.
     */
    private function bookkeeping(int $sourceId, string $url, ?string $code, CarbonImmutable $now): void
    {
        NewsSource::query()
            ->whereKey($sourceId)
            ->where('url', $url)
            ->update(['last_fetched_at' => $now, 'last_error_code' => $code]);
    }

    /**
     * @param  list<FeedEntry>  $entries
     */
    private function store(int $sourceId, array $entries, CarbonImmutable $now): void
    {
        $cutoff = $now->subDays((int) config('news.retention_days'));
        // Raw rows skip the casts: write them as Eloquent would, in the app timezone.
        $stamp = fn (CarbonImmutable $time) => $time->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        $rows = [];
        $firstSeen = [];

        foreach ($entries as $entry) {
            $hash = hash('sha256', $entry->guid);
            $dated = $entry->publishedAt !== null && ! $entry->publishedAt->greaterThan($now);
            $published = $dated ? $entry->publishedAt : $now;

            if (isset($rows[$hash]) || $published->lessThan($cutoff)) {
                continue;
            }

            $rows[$hash] = [
                'news_source_id' => $sourceId,
                'title' => $entry->title,
                'link' => $entry->link,
                'published_at' => $stamp($published),
                'guid_hash' => $hash,
                'created_at' => $stamp($now),
                'updated_at' => $stamp($now),
            ];

            if (! $dated) {
                $firstSeen[$hash] = true;
            }
        }

        $keep = (int) config('news.items_per_source');
        $rows = collect($rows)->sortByDesc('published_at')->take($keep);
        [$undated, $dated] = $rows->partition(fn (array $row) => isset($firstSeen[$row['guid_hash']]));
        $keys = ['news_source_id', 'guid_hash'];

        if ($dated->isNotEmpty()) {
            NewsItem::query()->upsert($dated->values()->all(), $keys, ['title', 'link', 'published_at', 'updated_at']);
        }

        // Undated (or future) items keep the date they got when first seen.
        if ($undated->isNotEmpty()) {
            NewsItem::query()->upsert($undated->values()->all(), $keys, ['title', 'link', 'updated_at']);
        }

        $items = NewsItem::query()->where('news_source_id', $sourceId);
        $newest = (clone $items)->orderByDesc('published_at')->orderByDesc('id')->limit($keep)->pluck('id');
        (clone $items)->whereNotIn('id', $newest)->delete();
    }

    /**
     * Whether another source of the same company and tab already reads
     * this feed (the comparison ignores case, as Admin's).
     */
    private function taken(NewsSource $source, string $feedUrl): bool
    {
        return NewsSource::query()
            ->whereKeyNot($source->id)
            ->where('company_id', $source->company_id)
            ->where('tab', $source->tab)
            ->whereRaw('LOWER(url) = ?', [mb_strtolower($feedUrl)])
            ->exists();
    }

    private function unexpected(NewsSource $source, Throwable $exception): string
    {
        // Only the class: a message could carry part of a response.
        Log::warning('News source could not be read.', ['news_source_id' => $source->id, 'exception' => $exception::class]);

        return FeedFailure::UNEXPECTED;
    }

    private function prune(): void
    {
        NewsItem::query()
            ->where('published_at', '<', CarbonImmutable::now()->subDays((int) config('news.retention_days')))
            ->delete();
    }
}
