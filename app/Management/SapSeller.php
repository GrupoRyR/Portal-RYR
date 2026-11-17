<?php

namespace App\Management;

/**
 * One seller of the month's top five (App\Management\SapFigureSet): the SAP
 * code (`slp_code`, -1 for sales without a seller), the name to show and
 * the seller's net sales of the month.
 */
final readonly class SapSeller
{
    public function __construct(
        public int $code,
        public string $name,
        public float $netSales,
    ) {}
}
