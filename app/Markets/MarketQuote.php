<?php

namespace App\Markets;

use Carbon\CarbonImmutable;

/**
 * One figure of the markets band: its latest value in the unit of its
 * series, the dates it is valid and how it moved against the previous
 * value (null when there is none to compare with).
 *
 * A price an admin typed by hand (App\Models\MarketInput) is `manual`: it
 * carries its own name and unit, since it is not in the catalogue
 * (App\Markets\MarketSeries), and its code is «input-<id>».
 */
final readonly class MarketQuote
{
    public function __construct(
        public string $code,
        public float $value,
        public CarbonImmutable $validFrom,
        public ?CarbonImmutable $validUntil,
        public ?float $change,
        public ?float $changePercent,
        public ?string $label = null,
        public ?MarketUnit $unit = null,
        public bool $manual = false,
    ) {}
}
