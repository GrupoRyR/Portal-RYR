<?php

namespace App\Models;

use App\Policies\AnnouncementPolicy;
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
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for event changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'events';

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
     * Writes a named audit entry; the causer is the signed-in user, if any.
     *
     * @param  array<string, mixed>  $properties
     */
    public function audit(string $event, array $properties = []): void
    {
        activity(self::AUDIT_LOG)
            ->performedOn($this)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
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
                $this->audit('event.companies_changed', [
                    'old' => ['companies' => $old],
                    'attributes' => ['companies' => $new],
                ]);
            }
        });

        return $this;
    }

    /**
     * Off the home screen and /eventos at once. Nothing is deleted;
     * {@see republish()} brings it back.
     */
    public function withdraw(): void
    {
        if ($this->withdrawn_at !== null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => now()])->save();
        $this->audit('event.withdrawn');
    }

    public function republish(): void
    {
        if ($this->withdrawn_at === null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => null])->save();
        $this->audit('event.republished');
    }

    public function isForAllCompanies(): bool
    {
        return $this->audience === self::AUDIENCE_ALL;
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
     * Events a user sees right now: published, not over, addressed to
     * their company. Users without a company (emergency accounts) see none.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->company_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->inStatus(self::STATUS_PUBLISHED)->forCompanyId((int) $user->company_id);
    }

    /**
     * Events addressed to the company, including those for every company.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeForCompanyId(Builder $query, int $companyId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('audience', self::AUDIENCE_ALL)
            ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($companyId)));
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
     * Events the user may edit (App\Policies\EventPolicy): all of them for
     * admins; for an announcements editor, those addressed to exactly
     * their own company; none for anybody else.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeManageableBy(Builder $query, User $user): void
    {
        if (AnnouncementPolicy::manages($user)) {
            return;
        }

        if ($user->company_id === null || ! $user->hasRole(AnnouncementPolicy::EDITOR)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $companyId = (int) $user->company_id;

        $query->where('audience', self::AUDIENCE_COMPANIES)
            ->whereHas('companies', fn (Builder $query) => $query->whereKey($companyId))
            ->whereDoesntHave('companies', fn (Builder $query) => $query->whereKeyNot($companyId));
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

    /**
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    private function companyNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Company::query()->whereKey($ids)->get(['id', 'name', 'short_name'])
            ->map(fn (Company $company) => $company->short_name ?: $company->name)
            ->sort()
            ->values()
            ->all();
    }
}
