<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

/**
 * One public holiday: the day off (midnight in the calendar timezone) and its
 * Spanish name, as shown to people.
 */
final readonly class Holiday
{
    public function __construct(
        public CarbonImmutable $date,
        public string $name,
    ) {}
}
