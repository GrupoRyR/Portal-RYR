<?php

namespace App\Models;

use App\Models\Concerns\AddressedToCompanies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A company event (odd/tasks/inicio-fase-2.md WU2): a date in «Próximas
 * fechas» of the home screen and on /eventos, with «Agregar a mi
 * calendario» (App\Calendar\EventCalendarFile). For every company or for
 * chosen ones, shown from `published_at` until it is over, unless
 * withdrawn: the same audience and withdrawal rules as announcements, and
 * the same people manage them (App\Policies\EventPolicy).
 *
 * Datetimes are in the app timezone (Bogotá). An all-day event keeps
 * midnight of its first day in `starts_at` and of its last day in
 * `ends_at` (null = one day). It is over ({@see endsAtMoment()}):
 * all-day, when its last day ends; timed, at `ends_at`, or when its day
 * ends if it has no end.
 *
 * @property-read string $status One of the STATUS_* values, at this moment.
 */
#[Fillable([
    'title',
    'description',
    'location',
    'starts_at',
    'ends_at',
    'all_day',
    'audience',
    'published_at',
    'created_by',
])]
class Event extends Model
{
    use AddressedToCompanies;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for event changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'events';

    /** Named audit entries: «event.withdrawn», «event.republished», «event.companies_changed». */
    public const AUDIT_EVENT_PREFIX = 'event';

    public const TITLE_MAX = 120;

    public const DESCRIPTION_MAX = 500;

    public const LOCATION_MAX = 120;

    public const AUDIENCE_ALL = Announcement::AUDIENCE_ALL;

    public const AUDIENCE_COMPANIES = Announcement::AUDIENCE_COMPANIES;

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_COMPANIES];

    public const STATUS_SCHEDULED = 'programado';

    public const STATUS_PUBLISHED = 'publicado';

    public const STATUS_PAST = 'pasado';

    public const STATUS_WITHDRAWN = 'retirado';

    /** In the order of the admin tabs. */
    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_SCHEDULED,
        self::STATUS_PAST,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'audience' => self::AUDIENCE_ALL,
        'all_day' => false,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    /**
     * Created and updated entries keep the before/after values of the
     * changed fields only. Withdrawing, republishing and a change of
     * companies get named entries.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::AUDIT_LOG)
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'event_company');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Saves the event and its companies in one transaction; a change of
     * companies gets its own audit entry. Authorization is the caller's job.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string>  $companyIds  Ignored when it is for every company.
     */
    public function saveWithCompanies(array $attributes, array $companyIds = []): self
    {
        DB::transaction(function () use ($attributes, $companyIds) {
            $old = $this->exists ? $this->companyNames($this->companies()->pluck('companies.id')->all()) : [];

            $this->fill($attributes)->save();

            $ids = $this->isForAllCompanies() ? [] : array_values(array_unique(array_map('intval', $companyIds)));
            $this->companies()->sync($ids);

            $new = $this->companyNames($ids);
            if ($new !== $old) {
                $this->audit(self::AUDIT_EVENT_PREFIX.'.companies_changed', [
                    'old' => ['companies' => $old],
                    'attributes' => ['companies' => $new],
                ]);
            }
        });

        return $this;
    }

    /**
     * Midnight of the last day, in the app timezone.
     */
    public function lastDay(): CarbonImmutable
    {
        return self::local($this->ends_at ?? $this->starts_at)->startOfDay();
    }

    /**
     * When it is over: the end of its last day (all-day), its end, or the
     * end of its day when a timed event has no end.
     */
    public function endsAtMoment(): CarbonImmutable
    {
        if (! $this->all_day && $this->ends_at !== null) {
            return self::local($this->ends_at);
        }

        return $this->lastDay()->addDay();
    }

    /**
     * Status at this moment: withdrawn wins, then the publication date,
     * then whether it is over.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            $this->withdrawn_at !== null => self::STATUS_WITHDRAWN,
            $this->published_at === null || $this->published_at > Carbon::now() => self::STATUS_SCHEDULED,
            $this->endsAtMoment() <= Carbon::now() => self::STATUS_PAST,
            default => self::STATUS_PUBLISHED,
        });
    }

    /**
     * Not over yet, whatever its status: the same rule as endsAtMoment().
     *
     * @param  Builder<Event>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $now = self::local(Carbon::now());
        $today = $now->startOfDay();

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->where('starts_at', '>=', $today))
            ->orWhere(fn (Builder $query) => $query->where('all_day', true)->whereNotNull('ends_at')->where('ends_at', '>=', $today))
            ->orWhere(fn (Builder $query) => $query->where('all_day', false)->whereNotNull('ends_at')->where('ends_at', '>', $now)));
    }

    /**
     * Same rule as the `status` attribute, as a query.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeInStatus(Builder $query, string $status): void
    {
        $now = self::local(Carbon::now());
        $live = fn (Builder $query) => $query->whereNull('withdrawn_at')->where('published_at', '<=', $now);

        match ($status) {
            self::STATUS_WITHDRAWN => $query->whereNotNull('withdrawn_at'),
            self::STATUS_SCHEDULED => $query->whereNull('withdrawn_at')->where('published_at', '>', $now),
            self::STATUS_PAST => $live($query)->whereNot(fn (Builder $query) => $query->upcoming()),
            self::STATUS_PUBLISHED => $live($query)->upcoming(),
            default => throw new InvalidArgumentException("Unknown event status [{$status}]."),
        };
    }

    /**
     * Soonest first.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('starts_at')->orderBy('id');
    }

    /**
     * The moment in the app timezone: the database stores local times.
     */
    private static function local(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone(config('app.timezone'));
    }
}
