<?php

namespace App\Models;

use Database\Factories\NewsSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An RSS or Atom feed shown in one tab of a company's «Noticias»
 * (Admin › Noticias, super admins only). `portal:fetch-news` reads the
 * active ones every hour (App\News\NewsUpdater) and records the time of
 * the attempt and, when it fails, a short code (App\News\FeedFailure).
 *
 * @property int $id
 * @property int $company_id
 * @property string $tab
 * @property string $name
 * @property string $url
 * @property bool $active
 * @property Carbon|null $last_fetched_at
 * @property string|null $last_error_code
 */
#[Fillable(['company_id', 'tab', 'name', 'url', 'active'])]
class NewsSource extends Model
{
    /** @use HasFactory<NewsSourceFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for news source changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'news';

    public const TAB_COLOMBIA = 'colombia';

    public const TAB_INTERNATIONAL = 'internacional';

    public const TAB_SECTOR = 'sector';

    /** In the order of the home tabs, with their labels. */
    public const TABS = [
        self::TAB_COLOMBIA => 'Colombia',
        self::TAB_INTERNATIONAL => 'Internacional',
        self::TAB_SECTOR => 'Sector',
    ];

    public const NAME_MAX = 80;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    /**
     * What an admin changes is logged with the before/after values; the
     * fetch bookkeeping (`last_fetched_at`, `last_error_code`) is not.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logOnly(['tab', 'name', 'url', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Created and deleted entries also name the company, which never
     * changes after creation.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (! in_array($eventName, ['created', 'deleted'], true)) {
            return;
        }

        $company = Company::query()->whereKey($this->company_id)->first(['name', 'short_name']);
        $key = $eventName === 'created' ? 'attributes' : 'old';
        $properties = $activity->properties->toArray();
        $properties[$key] = [...($properties[$key] ?? []), 'company' => $company?->short_name ?: $company?->name];
        $activity->properties = collect($properties);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<NewsItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(NewsItem::class);
    }

    public function tabLabel(): string
    {
        return self::TABS[$this->tab] ?? $this->tab;
    }
}
