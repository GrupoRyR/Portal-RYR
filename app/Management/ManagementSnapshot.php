<?php

namespace App\Management;

use Carbon\CarbonImmutable;

/**
 * A company's current set of management figures: the period («Octubre»),
 * the closing instant («al cierre de ayer»), the source line («SAP Business
 * One») and the figures in display order.
 */
final readonly class ManagementSnapshot
{
    /**
     * @param  list<ManagementKpi>  $items
     */
    public function __construct(
        public string $periodLabel,
        public CarbonImmutable $asOf,
        public string $sourceLabel,
        public array $items,
    ) {}
}
