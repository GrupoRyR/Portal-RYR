<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way to get an app (brief §5.2). Every rule belongs to a company:
 *
 * - `group`: members of the Microsoft security group whose display name is
 *   `value` (case-insensitive), among the users of `company_id` only, so a
 *   group with the same name in another tenant grants nothing; Microsoft
 *   365 groups (Teams) never count;
 * - `user`: the user whose id is `value`;
 * - `company`: everyone in `company_id` (`value` is null).
 *
 * Rules only apply to companies the app is linked to (`app_companies`, or
 * every active company when the app is `available_to_all_companies`).
 */
#[Fillable(['app_id', 'type', 'value', 'company_id'])]
class AppAccessRule extends Model
{
    public const TYPE_GROUP = 'group';

    public const TYPE_USER = 'user';

    public const TYPE_COMPANY = 'company';

    public const TYPES = [self::TYPE_GROUP, self::TYPE_USER, self::TYPE_COMPANY];

    /**
     * @return BelongsTo<App, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forGroup(App $app, Company $company, string $displayName): self
    {
        return self::firstOrCreate([
            'app_id' => $app->id,
            'type' => self::TYPE_GROUP,
            'value' => trim($displayName),
            'company_id' => $company->id,
        ]);
    }

    /**
     * A rule for one user, filed under the user's company.
     */
    public static function forUser(App $app, User $user): self
    {
        return self::firstOrCreate([
            'app_id' => $app->id,
            'type' => self::TYPE_USER,
            'value' => (string) $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    public static function forCompany(App $app, Company $company): self
    {
        return self::firstOrCreate([
            'app_id' => $app->id,
            'type' => self::TYPE_COMPANY,
            'value' => null,
            'company_id' => $company->id,
        ]);
    }

    /**
     * Whether this rule grants the app to the user, given the lowercase
     * display names of their security groups. The caller has already checked that
     * the user belongs to an active company linked to the app.
     *
     * @param  list<string>  $groupNames
     */
    public function grants(User $user, array $groupNames): bool
    {
        return match ($this->type) {
            self::TYPE_COMPANY => (int) $this->company_id === (int) $user->company_id,
            self::TYPE_USER => $this->value === (string) $user->id,
            self::TYPE_GROUP => (int) $this->company_id === (int) $user->company_id
                && in_array(mb_strtolower(trim((string) $this->value)), $groupNames, true),
            default => false,
        };
    }
}
