<?php

namespace App\View\Home;

use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * «Tareas pendientes» of «Mi día» (docs/contrato-tareas-aprobaciones.md §4):
 * the active account's own pending items (WorkItem::visibleTo()), «Por
 * aprobar» first, then «Tareas»; in each group the soonest due first (the
 * overdue ones lead) and those without a date last, at most LIMIT, with
 * «Ver todas» to the catalog app when there are more.
 *
 * Four queries (two groups, their integrations and catalog apps), none
 * for an account without a Microsoft identity (the emergency one).
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
     */
    public static function for(User $user, CarbonImmutable $now): ?self
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
            self::more($approvals),
            self::more($tasks),
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
     * «Ver todas» to each catalog app of the group, when it has more than LIMIT.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return list<array{href: string, app: string}>
     */
    private static function more(Collection $items): array
    {
        if ($items->count() <= self::LIMIT) {
            return [];
        }

        return $items
            ->map(fn (WorkItem $item) => $item->integration?->app)
            ->filter(fn ($app) => $app !== null && is_string($app->url) && $app->url !== '')
            ->unique('id')
            ->map(fn ($app) => ['href' => (string) $app->url, 'app' => (string) $app->name])
            ->values()
            ->all();
    }
}
