<?php

namespace Tests\Unit\Agent\Views;

use App\Agent\Views\AgentViews;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The views turn UTC into local time with a fixed offset
 * (AgentViews::UTC_OFFSET_HOURS), so the app time zone must be one with that
 * offset all year: this fails if somebody changes the zone (or it gets DST).
 */
class LocalTimeOffsetTest extends TestCase
{
    public function test_the_app_time_zone_is_utc_minus_five_all_year(): void
    {
        $zone = (string) config('app.timezone');

        for ($year = 2024; $year <= 2040; $year++) {
            foreach ([1, 3, 4, 6, 7, 9, 10, 11, 12] as $month) {
                foreach ([1, 15, 28] as $day) {
                    $moment = CarbonImmutable::create($year, $month, $day, 12, 0, 0, $zone);

                    $this->assertSame(
                        AgentViews::UTC_OFFSET_HOURS * 60,
                        $moment->utcOffset(),
                        "{$zone} is not at UTC".AgentViews::UTC_OFFSET_HOURS." on {$moment->toDateString()}",
                    );
                }
            }
        }
    }
}
