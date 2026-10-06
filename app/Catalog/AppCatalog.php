<?php

namespace App\Catalog;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppLaunch;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as BaseCollection;

/**
 * The one place that decides which apps a user gets (home, launch,
 * search). An app is visible when:
 *
 * 1. its status is not `hidden` (maintenance apps stay visible);
 * 2. it is linked to the user's company in `app_companies`, or it is
 *    flagged `available_to_all_companies` (every active company);
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

    /**
     * Distinct apps the user opened in the last 30 days, newest first,
     * limited to those still visible to them.
     *
     * @param  Collection<int, App>|null  $visible  the result of visibleApps()
     *                                              when the caller already has it
     * @return Collection<int, App>
     */
    public function recentApps(User $user, int $limit = 5, ?Collection $visible = null): Collection
    {
        if ($limit < 1 || ! $this->mayUseCatalog($user)) {
            return new Collection;
        }

        $visible = ($visible ?? $this->visibleApps($user))->keyBy('id');

        if ($visible->isEmpty()) {
            return new Collection;
        }

        return new Collection(
            $this->latestLaunchedAppIds($user, now()->subDays(30), $visible->keys()->all())
                ->take($limit)
                ->map(fn (int $appId) => $visible->get($appId))
                ->values()
                ->all(),
        );
    }

    /**
     * The app the user opened most recently within the last minutes, if
     * still visible. Soporte TI (Phase 7) uses it to preselect the app.
     */
    public function lastOpenedWithin(User $user, int $minutes = 30): ?App
    {
        if (! $this->mayUseCatalog($user)) {
            return null;
        }

        $visible = $this->visibleApps($user)->keyBy('id');
        $appId = $this->latestLaunchedAppIds($user, now()->subMinutes($minutes), $visible->keys()->all())->first();

        return $appId === null ? null : $visible->get($appId);
    }

    /**
     * Ids of the given apps the user launched since the date, ordered by
     * their latest launch (newest first), each once.
     *
     * @param  list<int>  $appIds
     * @return BaseCollection<int, int>
     */
    private function latestLaunchedAppIds(User $user, DateTimeInterface $since, array $appIds): BaseCollection
    {
        if ($appIds === []) {
            return collect();
        }

        return AppLaunch::query()
            ->where('user_id', $user->id)
            ->where('launched_at', '>=', $since)
            ->whereIn('app_id', $appIds)
            ->groupBy('app_id')
            ->selectRaw('app_id, MAX(launched_at) as last_launched_at')
            ->orderByDesc('last_launched_at')
            ->orderByDesc('app_id')
            ->pluck('app_id')
            ->map(fn ($id) => (int) $id);
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
            ->where(fn (Builder $query) => $query
                ->where('available_to_all_companies', true)
                ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($companyId)))
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
