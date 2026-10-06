<?php

namespace App\Audit;

use App\Models\App;
use App\Models\AppLaunch;
use App\Models\User;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * The unified, newest-first Auditoría list over `activity_log` and
 * `app_launches`.
 *
 * One page is one UNION ALL of the two sources, each already cut to the
 * page size by its own (time, id) index, then sorted and cut again: the
 * database never sorts more than two pages of keys, however large the
 * tables. Pages are keyset-paginated with an opaque cursor holding the last
 * row's (time, source, id), so deep pages cost the same as the first and
 * rows inserted meanwhile never shift a page. Only then are the 50 rows
 * hydrated, with eager loads per source.
 */
final class AuditFeed
{
    public const PER_PAGE = 50;

    public function __construct(private readonly AuditPresenter $presenter) {}

    /**
     * @return array{entries: list<AuditEntry>, next: ?string}
     */
    public function page(AuditFilters $filters, ?string $cursor = null, int $limit = self::PER_PAGE): array
    {
        $keys = $this->keys($filters, self::decode($cursor), $limit + 1);
        $more = count($keys) > $limit;
        $keys = array_slice($keys, 0, $limit);

        $last = end($keys);

        return [
            'entries' => $this->hydrate($keys),
            'next' => $more && $last ? self::encode($last) : null,
        ];
    }

    /**
     * Row keys in feed order: [occurred_at, kind, id].
     *
     * @param  array{0: string, 1: int, 2: int}|null  $after
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function keys(AuditFilters $filters, ?array $after, int $limit): array
    {
        $branches = [];

        if ($filters->includesChanges() || $filters->includesSecurity()) {
            $branches[] = $this->activityKeys($filters, $after, $limit);
        }

        if ($filters->includesLaunches()) {
            $branches[] = $this->launchKeys($filters, $after, $limit);
        }

        $query = DB::query()->fromSub(array_shift($branches), 'activity_page');
        foreach ($branches as $branch) {
            $query->unionAll(DB::query()->fromSub($branch, 'launch_page'));
        }

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('kind')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => [substr((string) $row->occurred_at, 0, 19), (int) $row->kind, (int) $row->id])
            ->all();
    }

    private function activityKeys(AuditFilters $filters, ?array $after, int $limit): Builder
    {
        $user = (new User)->getMorphClass();
        $query = DB::table('activity_log')
            ->selectRaw('created_at as occurred_at, '.AuditEntry::KIND_ACTIVITY.' as kind, id');

        if (! $filters->includesChanges()) {
            $query->where('log_name', SecurityLog::LOG);
        } elseif (! $filters->includesSecurity()) {
            $query->where(fn (Builder $query) => $query->whereNull('log_name')->orWhere('log_name', '!=', SecurityLog::LOG));
        }

        if ($filters->company) {
            $query->where('causer_type', $user)
                ->whereIn('causer_id', DB::table('users')->select('id')->where('company_id', $filters->company->id));
        }

        if ($filters->search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('causer_type', $user)->whereIn('causer_id', $this->matchingUsers($filters->search)))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', $user)->whereIn('subject_id', $this->matchingUsers($filters->search)))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', (new App)->getMorphClass())->whereIn('subject_id', $this->matchingApps($filters->search))));
        }

        $this->window($query, 'created_at', $filters, $after, AuditEntry::KIND_ACTIVITY);

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit);
    }

    private function launchKeys(AuditFilters $filters, ?array $after, int $limit): Builder
    {
        $query = DB::table('app_launches')
            ->selectRaw('launched_at as occurred_at, '.AuditEntry::KIND_LAUNCH.' as kind, id');

        if ($filters->company) {
            $query->where('company_id', $filters->company->id);
        }

        if ($filters->search !== '') {
            $query->where(fn (Builder $query) => $query
                ->whereIn('user_id', $this->matchingUsers($filters->search))
                ->orWhereIn('app_id', $this->matchingApps($filters->search)));
        }

        $this->window($query, 'launched_at', $filters, $after, AuditEntry::KIND_LAUNCH);

        return $query->orderByDesc('launched_at')->orderByDesc('id')->limit($limit);
    }

    /**
     * Period bounds plus the keyset condition «strictly after the cursor»
     * in (time desc, kind desc, id desc) order. The kind is constant within
     * a source, so the tuple comparison reduces to plain conditions.
     *
     * @param  array{0: string, 1: int, 2: int}|null  $after
     */
    private function window(Builder $query, string $column, AuditFilters $filters, ?array $after, int $kind): void
    {
        if ($start = $filters->start()) {
            $query->where($column, '>=', self::format($start));
        }

        if ($end = $filters->end()) {
            $query->where($column, '<=', self::format($end));
        }

        if ($after === null) {
            return;
        }

        [$time, $afterKind, $afterId] = $after;

        if ($kind < $afterKind) {
            $query->where($column, '<=', $time);
        } elseif ($kind > $afterKind) {
            $query->where($column, '<', $time);
        } else {
            $query->where(fn (Builder $query) => $query
                ->where($column, '<', $time)
                ->orWhere(fn (Builder $query) => $query->where($column, $time)->where('id', '<', $afterId)));
        }
    }

    private function matchingUsers(string $search): Builder
    {
        $like = Like::contains($search);

        return DB::table('users')->select('id')->where(fn (Builder $query) => $query
            ->whereRaw('lower(name) like ? '.Like::ESCAPE, [$like])
            ->orWhereRaw('lower(email) like ? '.Like::ESCAPE, [$like]));
    }

    private function matchingApps(string $search): Builder
    {
        $like = Like::contains($search);

        return DB::table('apps')->select('id')->where(fn (Builder $query) => $query
            ->whereRaw('lower(name) like ? '.Like::ESCAPE, [$like])
            ->orWhereRaw('lower(slug) like ? '.Like::ESCAPE, [$like]));
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $keys
     * @return list<AuditEntry>
     */
    private function hydrate(array $keys): array
    {
        $ids = fn (int $kind) => array_column(array_filter($keys, fn (array $key) => $key[1] === $kind), 2);

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->whereIn('id', $ids(AuditEntry::KIND_ACTIVITY))
            ->get()
            ->keyBy('id');

        // Causers' companies in one query, whatever the number of rows.
        (new EloquentCollection($activities->pluck('causer')->filter(fn ($causer) => $causer instanceof User)->unique('id')->values()->all()))
            ->load('company:id,name,short_name');

        $launches = AppLaunch::query()
            ->with(['user:id,name,email', 'app:id,name,slug', 'company:id,name,short_name'])
            ->whereIn('id', $ids(AuditEntry::KIND_LAUNCH))
            ->get()
            ->keyBy('id');

        $entries = [];
        foreach ($keys as [, $kind, $id]) {
            $model = $kind === AuditEntry::KIND_ACTIVITY ? $activities->get($id) : $launches->get($id);

            if ($model !== null) {
                $entries[] = $model instanceof Activity ? $this->presenter->activity($model) : $this->presenter->launch($model);
            }
        }

        return $entries;
    }

    /**
     * @param  array{0: string, 1: int, 2: int}  $key
     */
    private static function encode(array $key): string
    {
        return rtrim(strtr(base64_encode(json_encode($key)), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 200) {
            return null;
        }

        $key = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($key) || count($key) !== 3
            || ! is_string($key[0] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $key[0]) !== 1
            || ! in_array($key[1] ?? null, [AuditEntry::KIND_ACTIVITY, AuditEntry::KIND_LAUNCH], true)
            || ! is_int($key[2] ?? null)) {
            return null;
        }

        return [$key[0], $key[1], $key[2]];
    }

    /**
     * Stored timestamps are app-timezone strings (Bogotá).
     */
    private static function format(CarbonImmutable $moment): string
    {
        return $moment->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
