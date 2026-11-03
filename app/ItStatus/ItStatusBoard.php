<?php

namespace App\ItStatus;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Models\ItService;
use App\Models\User;
use App\Settings\PortalSettings;
use App\View\Home\AppCardStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read model of «Estado de TI» for one person: is anything broken for MY
 * company right now?
 *
 * - the apps the person can see (App\Catalog\AppCatalog) that are in
 *   maintenance or have a current degraded/down report, with the same
 *   precedence as the home cards (App\View\Home\AppCardStatus);
 * - the manual services of the person's company (Administración › Estado
 *   de TI) that are not operational, worst first;
 * - the overall level, the worst of both: a maintenance counts as a
 *   partial failure, a down report or service as down;
 * - «Reportar un problema»: the existing «Soporte TI» link (/soporte, to
 *   Gestor TI) when a support URL is configured.
 *
 * Not cached on purpose: failure reports expire with the clock
 * (`api_status_ttl_hours`), so a cache would need a time-based expiry as
 * well as invalidation on writes, and the only query of its own reads a
 * handful of rows through the (company_id, position) index. Pass the
 * visible apps when the caller already has them (the home does) and it
 * runs that single query.
 */
class ItStatusBoard
{
    public function __construct(
        private readonly AppCatalog $catalog,
        private readonly PortalSettings $settings,
    ) {}

    /**
     * @param  Collection<int, App>|null  $visible  the result of AppCatalog::visibleApps()
     *                                              when the caller already has it
     */
    public function for(User $user, ?Collection $visible = null): ItStatus
    {
        $reportHref = $this->settings->supportUrl() === null ? null : route('support');

        if (! self::hasActiveCompany($user)) {
            return new ItStatus(false, ItStatusLevel::Operational, [], [], $reportHref);
        }

        $apps = $this->apps($visible ?? $this->catalog->visibleApps($user));
        $services = $this->services((int) $user->company_id);

        return new ItStatus(
            true,
            ItStatusLevel::worst(array_map(fn (ItStatusItem $item) => $item->level, [...$apps, ...$services])),
            $apps,
            $services,
            $reportHref,
        );
    }

    /**
     * The same accounts that get apps (AppCatalog): a company, active, and
     * not the emergency account.
     */
    private static function hasActiveCompany(User $user): bool
    {
        return ! $user->is_emergency
            && $user->company_id !== null
            && $user->company?->is_active === true;
    }

    /**
     * @param  Collection<int, App>  $visible
     * @return list<ItStatusItem>
     */
    private function apps(Collection $visible): array
    {
        $items = [];

        foreach ($visible as $app) {
            $item = match (AppCardStatus::of($app)) {
                AppCardStatus::Maintenance => new ItStatusItem(
                    ItStatusItem::KIND_APP,
                    (string) $app->name,
                    ItStatusLevel::Degraded,
                    AppCardStatus::Maintenance->label(),
                    $app->maintenance_message,
                    $app->maintenance_until === null ? null : CarbonImmutable::instance($app->maintenance_until),
                    $app->slug,
                ),
                AppCardStatus::Failing => new ItStatusItem(
                    ItStatusItem::KIND_APP,
                    (string) $app->name,
                    ItStatusLevel::fromStatus($app->external_status),
                    AppCardStatus::Failing->label(),
                    $app->external_status_message,
                    null,
                    $app->slug,
                ),
                default => null,
            };

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<ItStatusItem>
     */
    private function services(int $companyId): array
    {
        return ItService::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', ItService::STATUS_OPERATIONAL)
            ->ordered()
            ->get(['id', 'name', 'status', 'message', 'position'])
            ->map(function (ItService $service) {
                $level = ItStatusLevel::fromStatus($service->status);

                return new ItStatusItem(ItStatusItem::KIND_SERVICE, $service->name, $level, $level->serviceLabel(), $service->message);
            })
            // sortBy is stable: within a level the admin order stays.
            ->sortBy(fn (ItStatusItem $item) => -$item->level->severity())
            ->values()
            ->all();
    }
}
