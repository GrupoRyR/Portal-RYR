<?php

namespace App\Management;

use Carbon\CarbonImmutable;

/**
 * The figures of one company for the current month, read from the «Bases
 * de datos SAP» views by App\Management\SapFigures, with the calculation
 * rules of odd/tasks/panel-gerencial-sap.md:
 *
 * - netSales: `SUM(total)` of the month's lines, credit notes negative, no
 *   filter on `estado_documento` (a voided invoice and its void cancel out);
 * - grossProfit: `SUM(ganancia_bruta)`; salesWithProfit: `SUM(total)` of the
 *   lines that bring `ganancia_bruta`, the base of the margin and of its
 *   coverage notice. `costo_total` is never read;
 * - receivables: `SUM(saldo)`, and the same balance by range («Sin vencer»
 *   and the seven `d_*` ranges), which must add up to it (rangesMatch());
 * - topSellers: up to five, by net sales.
 */
final readonly class SapFigureSet
{
    /** The range columns of `v_cartera`, in display order, with their label. */
    public const RANGES = [
        'sin_vencer' => 'Sin vencer',
        'd_0_30' => '0 a 30 días',
        'd_31_60' => '31 a 60 días',
        'd_61_90' => '61 a 90 días',
        'd_91_120' => '91 a 120 días',
        'd_121_150' => '121 a 150 días',
        'd_151_180' => '151 a 180 días',
        'd_mas_180' => 'Más de 180 días',
    ];

    /** Pesos the ranges may differ from the balance by (rounding of cents). */
    public const RANGE_TOLERANCE = 1.0;

    /**
     * @param  CarbonImmutable  $month  The first day of the month, in Bogotá.
     * @param  array<string, float>  $ranges  Keyed by the columns of self::RANGES, in its order.
     * @param  list<SapSeller>  $topSellers
     */
    public function __construct(
        public string $company,
        public CarbonImmutable $month,
        public float $netSales,
        public float $grossProfit,
        public float $salesWithProfit,
        public float $receivables,
        public array $ranges,
        public array $topSellers,
    ) {}

    /**
     * Gross profit over the net sale of the lines that bring it (0.25 is
     * 25 %); null when those lines add up to nothing.
     */
    public function margin(): ?float
    {
        return $this->salesWithProfit == 0.0 ? null : $this->grossProfit / $this->salesWithProfit;
    }

    /**
     * The share of the net sale the margin was computed over (1.0 is all of
     * it); null without net sales.
     */
    public function coverage(): ?float
    {
        return $this->netSales == 0.0 ? null : $this->salesWithProfit / $this->netSales;
    }

    public function rangesTotal(): float
    {
        return array_sum($this->ranges);
    }

    /** The balance minus the ranges: 0 when they add up. */
    public function rangesDifference(): float
    {
        return round($this->receivables - $this->rangesTotal(), 2);
    }

    /** Whether «Sin vencer» and the ranges add up to the balance, give or take cents. */
    public function rangesMatch(): bool
    {
        return abs($this->rangesDifference()) < self::RANGE_TOLERANCE;
    }
}
