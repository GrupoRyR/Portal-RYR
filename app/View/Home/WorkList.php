<?php

namespace App\View\Home;

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * «Tareas pendientes» of «Mi día» (docs/contrato-tareas-aprobaciones.md §4):
 * the active account's own pending items (WorkItem::visibleTo()), «Por
 * aprobar» first, then «Tareas»; in each group the soonest due first (the
 * overdue ones lead) and those without a date last, at most LIMIT, with
 * «Ver todas» to each catalog app of the group when there are more: only
 * apps the account sees in the catalog (AppCatalog::visibleApps()).
 *
 * Four queries (two groups, their integrations and catalog apps), and one
 * more for each group past LIMIT; none for an account without a Microsoft
 * identity (the emergency one).
 */
final readonly class WorkList
{
    public const LIMIT = 20;

    /**
     * @param  list<WorkRow>  $approvals
     * @param  list<WorkRow>  $tasks
     * @param  list<array{href: string, app: string}>  $moreApprovals  «Ver todas» per catalog app, when the group has more than LIMIT
     * @param  list<array{href: string, app: string}>  $moreTasks
     */
    public function __construct(
        public array $approvals,
        public array $tasks,
        public array $moreApprovals = [],
        public array $moreTasks = [],
    ) {}

    /**
     * Null when the account has nothing pending.
     *
     * @param  SupportCollection<int, App>  $visibleApps  AppCatalog::visibleApps() of the account
     */
    public static function for(User $user, CarbonImmutable $now, SupportCollection $visibleApps): ?self
    {
        if ($user->tenant_id === null || $user->azure_oid === null) {
            return null;
        }

        $approvals = self::group($user, WorkItem::KIND_APPROVAL);
        $tasks = self::group($user, WorkItem::KIND_TASK);

        if ($approvals->isEmpty() && $tasks->isEmpty()) {
            return null;
        }

        // Each row needs its integration and catalog app (the app's name,
        // «Ver todas» and WorkItem::canBeCompletedFromPortal()): one load for both groups.
        (new Collection([...$approvals, ...$tasks]))->load('integration.app');

        $rows = fn (Collection $items) => $items->take(self::LIMIT)->map(fn (WorkItem $item) => WorkRow::from($item, $now))->values()->all();

        return new self(
            $rows($approvals),
            $rows($tasks),
            self::more($user, WorkItem::KIND_APPROVAL, $approvals, $visibleApps),
            self::more($user, WorkItem::KIND_TASK, $tasks, $visibleApps),
        );
    }

    public function isEmpty(): bool
    {
        return $this->approvals === [] && $this->tasks === [];
    }

    /**
     * LIMIT + 1 items of one kind, so the block knows whether there are more.
     *
     * @return Collection<int, WorkItem>
     */
    private static function group(User $user, string $kind): Collection
    {
        return WorkItem::query()
            ->visibleTo($user)
            ->where('kind', $kind)
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    /**
     * «Ver todas» to each catalog app of the whole group (not only the rows
     * shown), by name, when it has more than LIMIT: one query for the apps
     * of its items, kept only when the account sees them in the catalog.
     *
     * @param  Collection<int, WorkItem>  $items  the LIMIT + 1 read
     * @param  SupportCollection<int, App>  $visibleApps
     * @return list<array{href: string, app: string}>
     */
    private static function more(User $user, string $kind, Collection $items, SupportCollection $visibleApps): array
    {
        if ($items->count() <= self::LIMIT) {
            return [];
        }

        $appIds = Integration::query()
            ->whereIn('id', WorkItem::query()->visibleTo($user)->where('kind', $kind)->select('integration_id'))
            ->whereNotNull('app_id')
            ->distinct()
            ->pluck('app_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $visibleApps
            ->filter(fn (App $app) => in_array((int) $app->id, $appIds, true) && WebLink::href($app->url) !== null)
            ->sortBy(fn (App $app) => mb_strtolower((string) $app->name))
            ->map(fn (App $app) => ['href' => (string) WebLink::href($app->url), 'app' => (string) $app->name])
            ->values()
            ->all();
    }
}
