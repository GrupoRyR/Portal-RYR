<?php

namespace App\View\Notifications;

use App\Models\Announcement;
use App\Models\Event;
use App\Models\User;
use App\Models\WorkItem;
use App\View\Home\EventDate;
use App\View\Home\WorkList;
use App\View\Home\WorkRow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The bell of the header (odd/tasks/liquid-glass.md G4), for the signed-in
 * account and its active company, rendered by the server (no polling, no
 * endpoint):
 *
 * - the mandatory announcements the person has not confirmed, newest first;
 * - the account's pending approvals, then its tasks (as «Tareas pendientes»:
 *   overdue and soonest due first);
 * - the events that start in the next EVENT_DAYS days, today included (or
 *   are under way), soonest first.
 *
 * At most LIMIT lines, in that order. The badge counts what needs the
 * person (the announcements and the pending items), exactly up to
 * BADGE_MAX and «9+» past it.
 *
 * The home screen builds it from what App\View\Home\HomePageBuilder already
 * read, plus one query (the unread announcements); the other pages read
 * it with for(), in at most QUERIES queries.
 */
final readonly class NotificationList
{
    public const LIMIT = 8;

    public const BADGE_MAX = 9;

    public const EVENT_DAYS = 7;

    /** The most queries for() runs. */
    public const QUERIES = 3;

    /**
     * @param  list<Notification>  $items
     * @param  int  $pending  What needs the person: exact up to BADGE_MAX, at least BADGE_MAX + 1 past it.
     */
    public function __construct(
        public array $items = [],
        public int $pending = 0,
    ) {}

    /**
     * For a page other than the home screen: the unread announcements, the
     * pending items and the events, a query each, none for what the account
     * cannot have (no company, no Microsoft identity).
     */
    public static function for(User $user, CarbonInterface $now): self
    {
        $now = CarbonImmutable::instance($now);
        $today = $now->setTimezone((string) config('app.timezone'))->startOfDay();

        $work = self::pendingItems($user);
        $events = $user->company_id === null ? [] : EventDate::soonestFirst(
            Event::visibleSoonest($user, self::LIMIT, ['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly']),
            $today,
        );

        return self::from(
            self::unreadAnnouncements($user),
            $work->map(fn (WorkItem $item) => Notification::work(
                $item->kind === WorkItem::KIND_APPROVAL,
                (string) $item->title,
                $item->url,
                WorkRow::due($item->due_at, $now)['label'],
            ))->all(),
            $work->count(),
            $events,
            $now,
        );
    }

    /**
     * For the home screen, from what its builder read: «Tareas pendientes»
     * and the events of «Próximas fechas».
     *
     * @param  Collection<int, Announcement>  $unread  unreadAnnouncements()
     * @param  list<EventDate>  $events  Soonest first.
     */
    public static function forHome(Collection $unread, ?WorkList $work, array $events, CarbonInterface $now): self
    {
        $rows = $work === null ? [] : [...$work->approvals, ...$work->tasks];

        return self::from(
            $unread,
            array_map(fn (WorkRow $row) => Notification::work($row->isApproval, $row->title, $row->url, $row->dueLabel), $rows),
            count($rows),
            $events,
            CarbonImmutable::instance($now),
        );
    }

    /**
     * The mandatory announcements the person has not confirmed with any of
     * their accounts, newest first: one query, none without a company.
     *
     * @return Collection<int, Announcement>
     */
    public static function unreadAnnouncements(User $user): Collection
    {
        if ($user->company_id === null) {
            return new Collection;
        }

        return Announcement::query()
            ->select(['id', 'title', 'starts_at'])
            ->visibleTo($user)
            ->where('requires_read', true)
            ->unreadByPersonOf($user)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::BADGE_MAX + 1)
            ->get();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** «3», «9+», or null with nothing pending. */
    public function badge(): ?string
    {
        return match (true) {
            $this->pending === 0 => null,
            $this->pending > self::BADGE_MAX => self::BADGE_MAX.'+',
            default => (string) $this->pending,
        };
    }

    /** The name of the bell: «Avisos, 3 sin leer». */
    public function label(): string
    {
        return match (true) {
            $this->pending === 0 => 'Avisos, ninguno sin leer',
            $this->pending > self::BADGE_MAX => 'Avisos, más de '.self::BADGE_MAX.' sin leer',
            default => "Avisos, {$this->pending} sin leer",
        };
    }

    /**
     * Approvals first, then tasks, at most BADGE_MAX + 1: enough for the
     * badge. One query, none for an account without a Microsoft identity.
     *
     * @return Collection<int, WorkItem>
     */
    private static function pendingItems(User $user): Collection
    {
        if ($user->tenant_id === null || $user->azure_oid === null) {
            return new Collection;
        }

        return WorkItem::query()
            ->select(['id', 'kind', 'title', 'url', 'due_at'])
            ->visibleTo($user)
            ->orderByRaw('case when kind = ? then 0 else 1 end', [WorkItem::KIND_APPROVAL])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::BADGE_MAX + 1)
            ->get();
    }

    /**
     * @param  Collection<int, Announcement>  $unread  Newest first.
     * @param  list<Notification>  $work  Approvals first.
     * @param  list<EventDate>  $events  Soonest first.
     */
    private static function from(Collection $unread, array $work, int $pendingWork, array $events, CarbonImmutable $now): self
    {
        $horizon = $now->setTimezone((string) config('app.timezone'))->startOfDay()->addDays(self::EVENT_DAYS);

        $items = [
            ...$unread->map(fn (Announcement $announcement) => Notification::announcement($announcement, $now))->all(),
            ...$work,
            ...array_map(
                Notification::event(...),
                array_values(array_filter($events, fn (EventDate $event) => $event->start < $horizon)),
            ),
        ];

        return new self(array_slice($items, 0, self::LIMIT), $unread->count() + $pendingWork);
    }
}
