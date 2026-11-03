<?php

namespace App\Markets;

use Carbon\CarbonImmutable;

/**
 * One figure of the markets band: its latest value in pesos, the dates it
 * is valid and how it moved against the previous value (null when there is
 * none to compare with).
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
    ) {}
}
