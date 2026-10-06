<?php

namespace App\Catalog;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one place that decides which apps a user gets (home, launch,
 * search). An app is visible when:
 *
 * 1. its status is not `hidden` (maintenance apps stay visible);
 * 2. it is linked to the user's company in `app_companies`;
 * 3. at least one access rule grants it (App\Models\AppAccessRule).
 *
 * Users without a company, users of an inactive company and emergency
 * accounts get nothing.
 */
class AppCatalog
{
    /**
     * Visible apps with their category, in catalog order. Runs a fixed
     * number of queries whatever the number of apps or rules.
     *
     * @return Collection<int, App>
     */
    public function visibleApps(User $user): Collection
    {
        if (! $this->mayUseCatalog($user)) {
            return new Collection;
        }

        $groupNames = $this->groupNames($user);

        return $this->candidates($user)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (App $app) => $this->granted($app, $user, $groupNames))
            ->values();
    }

    public function canOpen(User $user, App $app): bool
    {
        if (! $this->mayUseCatalog($user)) {
            return false;
        }

        $candidate = $this->candidates($user)->whereKey($app->getKey())->first();

        return $candidate !== null && $this->granted($candidate, $user, $this->groupNames($user));
    }

    private function mayUseCatalog(User $user): bool
    {
        return ! $user->is_emergency
            && $user->company_id !== null
            && $user->company?->is_active === true;
    }

    /**
     * Apps the user's company may see, with only the rules that could
     * apply to this user eager-loaded.
     *
     * @return Builder<App>
     */
    private function candidates(User $user): Builder
    {
        $companyId = $user->company_id;

        return App::query()
            ->where('status', '!=', App::STATUS_HIDDEN)
            ->whereHas('companies', fn (Builder $query) => $query->whereKey($companyId))
            ->with(['accessRules' => fn (HasMany $query) => $query->where(function (Builder $query) use ($user, $companyId) {
                $query->where('company_id', $companyId)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('type', AppAccessRule::TYPE_USER)
                        ->where('value', (string) $user->id));
            })]);
    }

    /**
     * @param  list<string>  $groupNames
     */
    private function granted(App $app, User $user, array $groupNames): bool
    {
        return $app->accessRules->contains(fn (AppAccessRule $rule) => $rule->grants($user, $groupNames));
    }

    /**
     * Lowercase display names of the user's stored groups.
     *
     * @return list<string>
     */
    private function groupNames(User $user): array
    {
        return $user->groups
            ->map(fn ($group) => mb_strtolower(trim((string) $group->display_name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
