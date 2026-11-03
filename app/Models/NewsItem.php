<?php

namespace App\Models;

use Database\Factories\NewsItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One headline of a news source: plain text title, https link and date
 * only (App\News\FeedParser). Unique per source by `guid_hash`; the newest
 * `news.items_per_source` per source are kept, for `news.retention_days`.
 *
 * @property int $id
 * @property int $news_source_id
 * @property string $title
 * @property string $link
 * @property Carbon $published_at
 * @property string $guid_hash
 */
#[Fillable(['news_source_id', 'title', 'link', 'published_at', 'guid_hash'])]
class NewsItem extends Model
{
    /** @use HasFactory<NewsItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'news_source_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NewsSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }
}
