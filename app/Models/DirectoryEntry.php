<?php

namespace App\Models;

use App\Catalog\AppSearch;
use App\Search\HomeSearchIndex;
use App\Support\Like;
use Database\Factories\DirectoryEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;

/**
 * One person of a company's directory, copied from Microsoft Graph every
 * night (App\Directory\DirectorySync). Only the company's own people see
 * it (companyIdFor()): the «Ctrl K» search of the home screen and
 * /personas.
 *
 * @property int $id
 * @property int $company_id
 * @property string $tenant_id
 * @property string $azure_oid
 * @property string $display_name
 * @property string|null $job_title
 * @property string|null $department
 * @property string|null $office_location
 * @property string|null $business_phone
 * @property string $email
 * @property Carbon|null $hire_date
 * @property string $search_text
 * @property Carbon $synced_at
 *
 * The home screen's directory query (App\View\Home\HomePageBuilder) also
 * reads `shown_birthday_month` and `shown_birthday_day`: the birthday of
 * the entry's account when it chose to show it (App\View\Home\Celebration).
 */
#[Fillable([
    'company_id',
    'tenant_id',
    'azure_oid',
    'display_name',
    'job_title',
    'department',
    'office_location',
    'business_phone',
    'email',
    'hire_date',
    'synced_at',
])]
class DirectoryEntry extends Model
{
    /** @use HasFactory<DirectoryEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The search text follows the fields on every save; the copy writes
     * it itself, as an upsert fires no model events.
     */
    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            $entry->search_text = self::searchTextFor($entry->only(self::SEARCHED));
        });
    }

    /** Fields the search looks through, in this order. */
    public const SEARCHED = ['display_name', 'job_title', 'department', 'office_location', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Tenant IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function tenantId(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    /**
     * Object IDs are GUIDs too.
     */
    protected function azureOid(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The company whose directory the user may see: their own, while it is
     * active. None for emergency accounts, accounts without a company and
     * guests (B2B) of another organization, who are not in the directory
     * either. Uses the user's loaded company.
     */
    public static function companyIdFor(?User $user): ?int
    {
        if ($user === null || $user->is_emergency || $user->is_guest === true || $user->company_id === null) {
            return null;
        }

        return $user->company?->is_active === true ? (int) $user->company_id : null;
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    public static function searchTextFor(array $fields): string
    {
        return AppSearch::normalize(implode(' ', array_filter(array_map(fn ($value) => (string) $value, $fields))));
    }

    /**
     * Entries holding every word of the search, ignoring accents and case
     * (App\Catalog\AppSearch), like the home search.
     *
     * @param  Builder<DirectoryEntry>  $query
     */
    public function scopeMatching(Builder $query, AppSearch $search): void
    {
        $terms = $search->isActive() ? explode(' ', AppSearch::normalize($search->query())) : [];

        foreach ($terms as $term) {
            $query->whereRaw('search_text like ? '.Like::ESCAPE, [Like::contains($term)]);
        }
    }

    /**
     * Adds `shown_birthday_month` and `shown_birthday_day`: the birthday of
     * the entry's own account (the one with its tenant, object ID and
     * company), only when that account chose to show it; null otherwise.
     * Never through `person_id`. Part of {@see homeDirectory()}. Select
     * the entry's columns with the table name before calling it.
     *
     * @param  Builder<DirectoryEntry>  $query
     */
    public function scopeWithShownBirthday(Builder $query): void
    {
        $query->addSelect([
            'users.birthday_month as shown_birthday_month',
            'users.birthday_day as shown_birthday_day',
        ])->leftJoin('users', function (JoinClause $join) {
            $join->on('users.tenant_id', '=', 'directory_entries.tenant_id')
                ->on('users.azure_oid', '=', 'directory_entries.azure_oid')
                ->on('users.company_id', '=', 'directory_entries.company_id')
                ->where('users.show_birthday', true);
        });
    }

    /**
     * The company directory as the home screen reads it, in one query: the
     * «Ctrl K» search index and «Cumpleaños y aniversarios» of «Mi día»
     * (App\View\Home\HomePageBuilder), and `cumpleanos_y_aniversarios` of
     * the Claude connector, so both list the same people. By name, at most
     * HomeSearchIndex::PEOPLE_LIMIT, only the columns those need, with the
     * shown birthday ({@see scopeWithShownBirthday()}). Whether the viewer
     * may see this directory is the caller's job ({@see companyIdFor()}).
     *
     * @return Collection<int, DirectoryEntry>
     */
    public static function homeDirectory(int $companyId): Collection
    {
        return self::query()
            ->select([
                'directory_entries.id',
                'directory_entries.tenant_id',
                'directory_entries.azure_oid',
                'directory_entries.display_name',
                'directory_entries.job_title',
                'directory_entries.department',
                'directory_entries.email',
                'directory_entries.hire_date',
                'directory_entries.search_text',
            ])
            ->withShownBirthday()
            ->where('directory_entries.company_id', $companyId)
            // Only directory_entries has display_name.
            ->orderBy('display_name')
            ->orderBy('directory_entries.id')
            ->limit(HomeSearchIndex::PEOPLE_LIMIT)
            ->get();
    }

    /**
     * «Cargo · Área», with whichever of the two is known.
     */
    public function roleLine(): string
    {
        return implode(' · ', array_filter([trim((string) $this->job_title), trim((string) $this->department)], fn (string $part) => $part !== ''));
    }

    /**
     * `tel:` link of the office phone: digits and a leading «+» only.
     */
    public function phoneHref(): ?string
    {
        $phone = trim((string) $this->business_phone);
        $digits = preg_replace('/[^\d+]/', '', $phone);

        if ($digits === null || $digits === '' || $digits === '+') {
            return null;
        }

        return 'tel:'.(str_starts_with($digits, '+') ? '+' : '').str_replace('+', '', $digits);
    }

    /**
     * `mailto:` link of the email, each part percent-encoded: a `?`, `&`
     * or `%` in the address never adds headers (cc, subject, body).
     *
     * @see https://www.rfc-editor.org/rfc/rfc6068
     */
    public function mailtoHref(): string
    {
        $email = (string) $this->email;
        $at = strrpos($email, '@');

        if ($at === false) {
            return 'mailto:'.rawurlencode($email);
        }

        return 'mailto:'.rawurlencode(substr($email, 0, $at)).'@'.rawurlencode(substr($email, $at + 1));
    }

    /**
     * A Teams chat with the person (opens Teams or its web app), with
     * $message written in the box, unsent («Felicitar» in «Mi día»).
     *
     * @see https://learn.microsoft.com/en-us/microsoftteams/platform/concepts/build-and-test/deep-link-teams
     */
    public function teamsChatUrl(?string $message = null): string
    {
        $url = 'https://teams.microsoft.com/l/chat/0/0?users='.rawurlencode($this->email);

        return $message === null ? $url : $url.'&message='.rawurlencode($message);
    }
}
