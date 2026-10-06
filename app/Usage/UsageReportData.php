<?php

namespace App\Usage;

use Carbon\CarbonImmutable;

/**
 * The numbers of Informe de uso for one period, all from real rows.
 */
final readonly class UsageReportData
{
    /**
     * @param  array<string, int>  $activeUsers  Active company label => active users.
     * @param  array<string, int>  $deviceCounts  desktop/tablet/phone => launches.
     * @param  array<string, int>  $devicePercentages  Same keys, whole percentages adding up to 100 (empty without launches).
     * @param  list<array{name: string, launches: int, width: string}>  $topApps  Most opened first; width is the bar length in %.
     * @param  list<array{name: string, last_launched_at: ?CarbonImmutable}>  $unusedApps  Not hidden, no launch in 90 days.
     */
    public function __construct(
        public int $days,
        public CarbonImmutable $start,
        public array $activeUsers,
        public int $totalLaunches,
        public array $deviceCounts,
        public array $devicePercentages,
        public array $topApps,
        public array $unusedApps,
    ) {}
}
