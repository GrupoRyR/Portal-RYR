<?php

namespace App\Usage;

use App\Models\App;
use App\Models\Company;
use App\Support\DeviceClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Informe de uso (brief §5.2): computed from `app_launches` and
 * `users.last_login_at` with a fixed number of grouped queries, whatever
 * the amount of data.
 *
 * A period of N days is today plus the N-1 previous calendar days in the
 * app timezone (Bogotá), up to now. An active user is someone of an active
 * company (emergency accounts excluded) who opened at least one app OR
 * signed in to the portal during the period; they count for the company
 * they belong to now. «Sin uso en 90 días» always looks at the last 90 days,
 * whatever period is selected.
 */
final class UsageReport
{
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    public const UNUSED_DAYS = 90;

    public const TOP_APPS = 10;

    /** Order of the device meter and its legend. */
    public const DEVICES = [DeviceClassifier::DESKTOP, DeviceClassifier::TABLET, DeviceClassifier::PHONE];

    public function build(int $days): UsageReportData
    {
        $days = in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
        $start = self::startOf($days);
        $from = self::format($start);
        $to = self::format(CarbonImmutable::now());

        $deviceCounts = $this->deviceCounts($from, $to);

        return new UsageReportData(
            $days,
            $start,
            $this->activeUsers($from, $to),
            array_sum($deviceCounts),
            $deviceCounts,
            self::percentages($deviceCounts),
            $this->topApps($from, $to),
            $this->unusedApps(self::format(self::startOf(self::UNUSED_DAYS))),
        );
    }

    public static function startOf(int $days): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay()->subDays($days - 1);
    }

    /**
     * Whole percentages that add up to 100 (largest remainder), so the
     * meter and its labels never show 99 % or 101 %.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function percentages(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return [];
        }

        $exact = array_map(fn (int $count) => $count * 100 / $total, $counts);
        $whole = array_map(fn (float $value) => (int) floor($value), $exact);
        $remainders = array_map(fn (float $value, int $floor) => $value - $floor, $exact, $whole);
        $order = array_keys($counts);
        array_multisort($remainders, SORT_DESC, $order);

        foreach (array_slice($order, 0, 100 - array_sum($whole)) as $key) {
            $whole[$key]++;
        }

        return $whole;
    }

    /**
     * @return array<string, int>
     */
    private function activeUsers(string $from, string $to): array
    {
        $companies = Company::active()->orderBy('name')->get(['id', 'name', 'short_name']);

        $counts = DB::table('users')
            ->selectRaw('company_id, count(*) as total')
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('is_emergency', false)
            ->where(fn (Builder $query) => $query
                ->whereBetween('last_login_at', [$from, $to])
                ->orWhereExists(fn (Builder $query) => $query->selectRaw('1')
                    ->from('app_launches')
                    ->whereColumn('app_launches.user_id', 'users.id')
                    ->whereBetween('launched_at', [$from, $to])))
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        return $companies
            ->mapWithKeys(fn (Company $company) => [($company->short_name ?: $company->name) => (int) ($counts[$company->id] ?? 0)])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function deviceCounts(string $from, string $to): array
    {
        $rows = DB::table('app_launches')
            ->selectRaw('device, count(*) as total')
            ->whereBetween('launched_at', [$from, $to])
            ->groupBy('device')
            ->pluck('total', 'device');

        $counts = [];
        foreach (self::DEVICES as $device) {
            $counts[$device] = (int) ($rows[$device] ?? 0);
        }

        return $counts;
    }

    /**
     * @return list<array{name: string, launches: int, width: string}>
     */
    private function topApps(string $from, string $to): array
    {
        $rows = DB::table('app_launches')
            ->join('apps', 'apps.id', '=', 'app_launches.app_id')
            ->selectRaw('apps.name, count(*) as launches')
            ->whereBetween('app_launches.launched_at', [$from, $to])
            ->groupBy('apps.id', 'apps.name')
            ->orderByDesc('launches')
            ->orderBy('apps.name')
            ->limit(self::TOP_APPS)
            ->get();

        $max = (int) ($rows->first()->launches ?? 0);

        return $rows->map(fn (object $row) => [
            'name' => (string) $row->name,
            'launches' => (int) $row->launches,
            'width' => self::width((int) $row->launches, $max),
        ])->all();
    }

    /**
     * @return list<array{name: string, last_launched_at: ?CarbonImmutable}>
     */
    private function unusedApps(string $since): array
    {
        return DB::table('apps')
            ->select('apps.name')
            ->selectSub(fn (Builder $query) => $query->selectRaw('max(launched_at)')->from('app_launches')->whereColumn('app_launches.app_id', 'apps.id'), 'last_launched_at')
            ->where('status', '!=', App::STATUS_HIDDEN)
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('app_launches')
                ->whereColumn('app_launches.app_id', 'apps.id')
                ->where('launched_at', '>=', $since))
            ->orderBy('apps.name')
            ->get()
            ->map(fn (object $row) => [
                'name' => (string) $row->name,
                'last_launched_at' => $row->last_launched_at === null
                    ? null
                    : CarbonImmutable::parse($row->last_launched_at, config('app.timezone')),
            ])
            ->all();
    }

    private static function width(int $value, int $max): string
    {
        $percent = $max === 0 ? 0 : round($value * 100 / $max, 1);

        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }

    private static function format(CarbonImmutable $moment): string
    {
        return $moment->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
