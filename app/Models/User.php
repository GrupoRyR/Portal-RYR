<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Microsoft\UserGroup;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who signs in with Microsoft. Identified by the object ID
 * (`azure_oid`) inside their home tenant (`tenant_id`); the password is only
 * used by non-Microsoft accounts and is otherwise null.
 *
 * `photo_path` is no longer used: sign-in stores no photo and
 * `portal:purge-public-avatars` clears it.
 *
 * `microsoft_login_hint` and `is_guest` are written only by the Microsoft
 * sign-in (App\Auth\MicrosoftLogin), so they are not fillable. `is_guest`
 * is null until the account signs in again after it was added: not known.
 *
 * `person_id` links the accounts one person has in several companies. Only
 * App\Auth\LinkedAccounts writes it, so it is not fillable either, and it
 * never authorizes anything: roles, groups, favorites and app access stay
 * per account.
 *
 * `birthday_month`, `birthday_day` and `show_birthday` («Mi cumpleaños» on
 * /cuenta) are written only by App\Http\Controllers\Account\BirthdayController,
 * so they are not fillable: the day and month only, never the year, shown
 * to the account's company in «Mi día» only while `show_birthday` is on.
 *
 * Emergency accounts (`is_emergency`) are local: password + TOTP, no
 * tenant, object ID or company. They are managed only through the
 * `portal:emergency-account` command, so the 2FA columns are not fillable.
 */
#[Fillable([
    'name',
    'email',
    'password',
    'azure_oid',
    'tenant_id',
    'company_id',
    'job_title',
    'photo_path',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_emergency' => 'boolean',
            'is_guest' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
            'birthday_month' => 'integer',
            'birthday_day' => 'integer',
            'show_birthday' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * The other accounts of this account's person (none when it is not
     * linked). Not for eager loading: it leaves out this model's own key.
     *
     * @return HasMany<User, $this>
     */
    public function linkedAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'person_id', 'person_id')->whereKeyNot($this->getKey());
    }

    /**
     * @return HasMany<UserGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(UserGroup::class);
    }

    /**
     * Apps the user pinned to the top of the home screen.
     *
     * @return BelongsToMany<App, $this>
     */
    public function favoriteApps(): BelongsToMany
    {
        return $this->belongsToMany(App::class, 'user_favorites')->withTimestamps();
    }

    /**
     * @return HasMany<AppLaunch, $this>
     */
    public function launches(): HasMany
    {
        return $this->hasMany(AppLaunch::class);
    }

    /**
     * Replace the stored groups with the given set, in one transaction.
     * Items without an ID are skipped; repeated IDs keep the first item.
     * `security_group` is stored as given (null when absent: not known).
     *
     * @param  iterable<array{id?: string|null, display_name?: string|null, security_group?: bool|null}>  $groups
     */
    public function syncGroups(iterable $groups): void
    {
        $wanted = collect($groups)
            ->map(fn (array $group) => [
                'group_id' => strtolower(trim((string) ($group['id'] ?? ''))),
                'display_name' => trim((string) ($group['display_name'] ?? '')),
                'security_group' => isset($group['security_group']) ? $group['security_group'] === true : null,
            ])
            ->filter(fn (array $group) => $group['group_id'] !== '')
            ->unique('group_id')
            ->keyBy('group_id');

        DB::transaction(function () use ($wanted) {
            $this->groups()->whereNotIn('group_id', $wanted->keys()->all())->delete();

            $existing = $this->groups()->get()->keyBy('group_id');

            foreach ($wanted as $groupId => $group) {
                $stored = $existing->get($groupId);

                if ($stored === null) {
                    $this->groups()->create($group);
                } else {
                    $stored->update($group);
                }
            }
        });

        $this->unsetRelation('groups');
    }

    /**
     * «5 de marzo», or null without a birthday.
     */
    public function birthdayLabel(): ?string
    {
        if ($this->birthday_month === null || $this->birthday_day === null) {
            return null;
        }

        // A leap year, so 29 February has a name too.
        return CarbonImmutable::create(2024, $this->birthday_month, $this->birthday_day)->locale('es')->isoFormat('D [de] MMMM');
    }

    /**
     * Object IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function azureOid(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizeGuid($value));
    }

    /**
     * Tenant IDs are GUIDs; Microsoft may send them in any case.
     */
    protected function tenantId(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizeGuid($value));
    }

    private static function normalizeGuid(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
