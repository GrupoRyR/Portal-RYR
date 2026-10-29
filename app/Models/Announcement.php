<?php

namespace App\Models;

use App\Policies\AnnouncementPolicy;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An announcement on the home screen (brief §4 «Anuncios»): plain text,
 * an optional 16:9 WebP image, for every company or for chosen ones, shown
 * from `starts_at` until `ends_at` (null = no end) unless withdrawn.
 *
 * A mandatory one (`requires_read`) asks each account that sees it to
 * confirm it was read ({@see markReadBy()}); editors see which accounts did
 * ({@see readCounts()}, {@see pendingReaders()}). Per account, never per
 * person: `users.person_id` decides nothing outside App\Auth\LinkedAccounts
 * (tests/Unit/Auth/PersonNeverAuthorizesTest.php), so someone with accounts
 * in two companies confirms once with each.
 *
 * @property-read string $status One of the STATUS_* values, at this moment.
 */
#[Fillable([
    'title',
    'body',
    'image_path',
    'audience',
    'starts_at',
    'ends_at',
    'is_pinned',
    'requires_read',
    'author_id',
])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for announcement changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'announcements';

    /** Uploaded images live on the "public" disk under {IMAGE_DIR}/{id}/. */
    public const IMAGE_DIR = 'announcements';

    public const TITLE_MAX = 120;

    public const BODY_MAX = 280;

    /** Accounts in the «Cuentas que faltan» list of the admin editor. */
    public const PENDING_READERS_LIMIT = 200;

    /**
     * Announcements per page of /anuncios (App\Http\Controllers\AnnouncementController),
     * also used to find the page of one of them (its show route).
     */
    public const PER_PAGE = 10;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_COMPANIES = 'companies';

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_COMPANIES];

    public const STATUS_SCHEDULED = 'programado';

    public const STATUS_PUBLISHED = 'publicado';

    public const STATUS_EXPIRED = 'vencido';

    public const STATUS_WITHDRAWN = 'retirado';

    /** In the order of the admin tabs. */
    public const STATUSES = [
        self::STATUS_PUBLISHED,
        self::STATUS_SCHEDULED,
        self::STATUS_EXPIRED,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'audience' => self::AUDIENCE_ALL,
        'is_pinned' => false,
        'requires_read' => false,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'is_pinned' => 'boolean',
            'requires_read' => 'boolean',
        ];
    }

    /**
     * Created and updated entries keep the before/after values of the
     * changed fields only. Withdrawing and republishing get named entries.
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
        return $this->belongsToMany(Company::class, 'announcement_company');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Records that the user read it, once: a second call keeps the first
     * date. Whether the user may see it is the caller's job.
     */
    public function markReadBy(User $user): AnnouncementRead
    {
        return AnnouncementRead::query()->createOrFirst(
            ['announcement_id' => $this->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /**
     * The accounts expected to read it: those of its companies (every
     * company when it is for all) that are active, without emergency or
     * guest accounts. Only people who already signed in have an account.
     *
     * By the account's company now: an account that moves to another
     * company leaves X and Y of an announcement only for the old one (its
     * read is kept and counts again if it comes back).
     *
     * `is_guest = null` (the account has not signed in since the flag was
     * added, so it is not known yet) counts: leaving it out could hide a
     * member who must read it, while a guest counted by mistake only shows
     * up as one more pending account until its next sign-in sets the flag.
     *
     * @return Builder<User>
     */
    public function audience(): Builder
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->when(! $this->isForAllCompanies(), fn (Builder $query) => $query->whereIn(
                'id',
                DB::table('announcement_company')->where('announcement_id', $this->id)->select('company_id'),
            ))
            ->select('id');

        return User::query()
            ->where('is_emergency', false)
            ->where(fn (Builder $query) => $query->whereNull('is_guest')->orWhere('is_guest', false))
            ->whereIn('company_id', $companies);
    }

    /**
     * «X de Y cuentas lo leyeron»: reads by accounts of the audience
     * only, so a read left from an earlier audience does not count.
     *
     * @return array{read: int, total: int}
     */
    public function readCounts(): array
    {
        return [
            'read' => $this->audience()->whereIn('id', $this->reads()->select('user_id'))->count(),
            'total' => $this->audience()->count(),
        ];
    }

    /**
     * Accounts of the audience that have not confirmed yet, alphabetical,
     * at most PENDING_READERS_LIMIT: the name and the short name of the
     * account's company (one person may have an account in each company).
     *
     * @return list<array{name: string, company: string}>
     */
    public function pendingReaders(): array
    {
        return $this->audience()
            ->whereNotIn('id', $this->reads()->select('user_id'))
            ->with('company:id,short_name')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::PENDING_READERS_LIMIT)
            ->get(['id', 'name', 'company_id'])
            ->map(fn (User $user) => [
                'name' => (string) $user->name,
                'company' => (string) $user->company?->short_name,
            ])
            ->values()
            ->all();
    }

    /**
     * Takes the announcement off the home screen at once. Nothing is
     * deleted; {@see republish()} brings it back.
     */
    public function withdraw(): void
    {
        if ($this->withdrawn_at !== null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => now()])->save();
        $this->audit('announcement.withdrawn');
    }

    public function republish(): void
    {
        if ($this->withdrawn_at === null) {
            return;
        }

        $this->forceFill(['withdrawn_at' => null])->save();
        $this->audit('announcement.republished');
    }

    public function isForAllCompanies(): bool
    {
        return $this->audience === self::AUDIENCE_ALL;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path === null ? null : Storage::disk('public')->url($this->image_path);
    }

    /**
     * Status at this moment: withdrawn wins, then the window decides.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn () => self::statusAt(
            Carbon::now(),
            $this->withdrawn_at,
            $this->starts_at,
            $this->ends_at,
        ));
    }

    public static function statusAt(\DateTimeInterface $now, ?\DateTimeInterface $withdrawnAt, ?\DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt): string
    {
        return match (true) {
            $withdrawnAt !== null => self::STATUS_WITHDRAWN,
            $startsAt === null || $startsAt > $now => self::STATUS_SCHEDULED,
            $endsAt !== null && $endsAt <= $now => self::STATUS_EXPIRED,
            default => self::STATUS_PUBLISHED,
        };
    }

    /**
     * Announcements a user sees on the home screen right now: published and
     * addressed to their company. Users without a company see none.
     *
     * @param  Builder<Announcement>  $query
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
     * Announcements addressed to the company, including those for every
     * company, whatever their status.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeForCompany(Builder $query, Company $company): void
    {
        $query->forCompanyId($company->id);
    }

    /**
     * @param  Builder<Announcement>  $query
     */
    public function scopeForCompanyId(Builder $query, int $companyId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('audience', self::AUDIENCE_ALL)
            ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($companyId)));
    }

    /**
     * Same rule as the `status` attribute, as a query.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeInStatus(Builder $query, string $status): void
    {
        $now = Carbon::now();

        match ($status) {
            self::STATUS_WITHDRAWN => $query->whereNotNull('withdrawn_at'),
            self::STATUS_SCHEDULED => $query->whereNull('withdrawn_at')->where('starts_at', '>', $now),
            self::STATUS_EXPIRED => $query->whereNull('withdrawn_at')
                ->where('starts_at', '<=', $now)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $now),
            self::STATUS_PUBLISHED => $query->whereNull('withdrawn_at')
                ->where('starts_at', '<=', $now)
                ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            default => throw new InvalidArgumentException("Unknown announcement status [{$status}]."),
        };
    }

    /**
     * Announcements the user may edit (App\Policies\AnnouncementPolicy):
     * all of them for admins; for an announcements editor, those addressed
     * to exactly their own company; none for anybody else.
     *
     * @param  Builder<Announcement>  $query
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
     * Adds `viewer_read_at`: when the user confirmed reading each
     * announcement, or null. A subquery, so it costs no extra query.
     * Select the announcement columns before calling it.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeWithReadAtFor(Builder $query, User $user): void
    {
        $query->addSelect(['viewer_read_at' => AnnouncementRead::query()
            ->select('read_at')
            ->whereColumn('announcement_reads.announcement_id', 'announcements.id')
            ->where('announcement_reads.user_id', $user->id)
            ->limit(1),
        ])->withCasts(['viewer_read_at' => 'datetime']);
    }

    /**
     * Pinned first, then the newest start.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByDesc('starts_at')->orderByDesc('id');
    }
}
