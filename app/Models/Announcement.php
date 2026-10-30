<?php

namespace App\Models;

use App\Auth\LinkedAccounts;
use App\Models\Concerns\AddressedToCompanies;
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
use Illuminate\Support\Collection;
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
 * A mandatory one (`requires_read`) asks each person who sees it to
 * confirm it was read ({@see markReadBy()}); editors see which people did
 * ({@see readCounts()}, {@see pendingReaders()}). Read per person: linked
 * accounts (App\Auth\LinkedAccounts) confirm once, with any of them, and
 * an account that is not linked is a person of its own. The person only
 * groups the reads: seeing the announcement is still per account
 * ({@see scopeVisibleTo()}, App\Policies\AnnouncementPolicy), and the
 * person is read only through LinkedAccounts
 * (tests/Unit/Auth/PersonNeverAuthorizesTest.php).
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
    use AddressedToCompanies;

    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    use LogsActivity;

    /** Activity log name for announcement changes (spatie/laravel-activitylog). */
    public const AUDIT_LOG = 'announcements';

    /** Named audit entries: «announcement.withdrawn», «announcement.republished», «announcement.companies_changed». */
    public const AUDIT_EVENT_PREFIX = 'announcement';

    /** Uploaded images live on the "public" disk under {IMAGE_DIR}/{id}/. */
    public const IMAGE_DIR = 'announcements';

    public const TITLE_MAX = 120;

    public const BODY_MAX = 280;

    /** People in the «Personas que faltan» list of the admin editor. */
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
     * «Enviar recordatorio» requests (App\Announcements\AnnouncementReminders).
     *
     * @return HasMany<AnnouncementReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(AnnouncementReminder::class);
    }

    /**
     * Records that the account read it, once: a second call keeps the
     * first date. Whether the user may see it is the caller's job; whether
     * the person already read it with another account is
     * {@see isReadByPersonOf()}.
     */
    public function markReadBy(User $user): AnnouncementRead
    {
        return AnnouncementRead::query()->createOrFirst(
            ['announcement_id' => $this->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /**
     * Whether the user's person read it, with any of their accounts (the
     * user's own when it is not linked).
     */
    public function isReadByPersonOf(User $user): bool
    {
        return $this->reads()->whereIn('user_id', LinkedAccounts::idsOfSamePerson($user))->exists();
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
     * «X de Y personas lo leyeron»: Y is the people behind the audience's
     * accounts, X those of them who read it with any of their accounts
     * (the same rule as the viewer's «Leído», {@see scopeWithReadAtFor()}),
     * so a read left from an earlier audience counts only for a person who
     * is still in it.
     *
     * @return array{read: int, total: int}
     */
    public function readCounts(): array
    {
        $key = DB::raw(LinkedAccounts::PERSON_KEY);

        return [
            'read' => $this->audience()->whereIn($key, $this->readersByPerson())->distinct()->count($key),
            'total' => $this->audience()->distinct()->count($key),
        ];
    }

    /**
     * People of the audience who have not confirmed yet, alphabetical, at
     * most PENDING_READERS_LIMIT: a name and the short names of the
     * person's companies, only those of their accounts in the audience (an
     * editor never learns that someone also works in a company the
     * announcement does not address). Two queries: the people, then their
     * accounts.
     *
     * @return list<array{name: string, companies: list<string>}>
     */
    public function pendingReaders(): array
    {
        $key = DB::raw(LinkedAccounts::PERSON_KEY);

        $people = $this->audience()
            ->whereNotIn($key, $this->readersByPerson())
            ->toBase()
            ->selectRaw(LinkedAccounts::PERSON_KEY.' as person_key, min(name) as person_name')
            ->groupBy($key)
            ->orderBy('person_name')
            ->orderBy('person_key')
            ->limit(self::PENDING_READERS_LIMIT)
            ->get();

        if ($people->isEmpty()) {
            return [];
        }

        $companies = $this->audience()
            ->whereIn($key, $people->pluck('person_key'))
            ->with('company:id,short_name')
            ->select(['id', 'company_id'])
            ->selectRaw(LinkedAccounts::PERSON_KEY.' as person_key')
            ->get()
            ->groupBy(fn (User $account) => (int) $account->getAttribute('person_key'))
            ->map(fn ($accounts) => $accounts
                ->map(fn (User $account) => (string) $account->company?->short_name)
                ->filter(fn (string $name) => $name !== '')
                ->unique()
                ->sort()
                ->values()
                ->all());

        return $people
            ->map(fn (object $person) => [
                'name' => (string) $person->person_name,
                'companies' => $companies->get((int) $person->person_key, []),
            ])
            ->values()
            ->all();
    }

    /**
     * Who gets the reminder now: the people of the audience who have not
     * confirmed yet (the rule of {@see pendingReaders()}, read at send
     * time, so whoever confirmed meanwhile is left out) and were not
     * mailed for this reminder yet: neither under the key recorded then
     * nor with any of their accounts now (someone linked after being
     * mailed has another key). One account per person: the lowest id
     * among their accounts in the audience. At most $limit, by person key,
     * in one query.
     *
     * @return Collection<int, int> person key by account id
     */
    public function reminderCandidates(AnnouncementReminder $reminder, int $limit): Collection
    {
        $key = DB::raw(LinkedAccounts::PERSON_KEY);

        $mailedNow = User::query()
            ->whereIn('id', $reminder->deliveries()->select('user_id'))
            ->select($key);

        return $this->audience()
            ->whereNotIn($key, $this->readersByPerson())
            ->whereNotIn($key, $reminder->deliveries()->select('person_key'))
            ->whereNotIn($key, $mailedNow)
            ->toBase()
            ->selectRaw('min(users.id) as id, '.LinkedAccounts::PERSON_KEY.' as person_key')
            ->groupBy($key)
            ->orderBy('person_key')
            ->limit($limit)
            ->pluck('person_key', 'id')
            ->map(fn ($personKey) => (int) $personKey);
    }

    /**
     * The person keys ({@see LinkedAccounts::PERSON_KEY}) of every account
     * that read it, as a subquery.
     *
     * @return Builder<User>
     */
    private function readersByPerson(): Builder
    {
        return User::query()
            ->whereIn('id', $this->reads()->select('user_id'))
            ->select(DB::raw(LinkedAccounts::PERSON_KEY));
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
     * Adds `viewer_read_at`: when the user's person first confirmed reading
     * each announcement, with any of their accounts, or null. A subquery
     * (the person is a bound value), so it costs no extra query. Select
     * the announcement columns before calling it.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeWithReadAtFor(Builder $query, User $user): void
    {
        $query->addSelect(['viewer_read_at' => AnnouncementRead::query()
            ->selectRaw('min(announcement_reads.read_at)')
            ->whereColumn('announcement_reads.announcement_id', 'announcements.id')
            ->whereIn('announcement_reads.user_id', LinkedAccounts::idsOfSamePerson($user)),
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
