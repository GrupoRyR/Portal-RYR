<?php

namespace App\Management;

/**
 * One figure of the management panel, e.g. «Ventas del mes» against its
 * goal (App\Management\ManagementPanel).
 */
final readonly class ManagementKpi
{
    public function __construct(
        public string $key,
        public string $label,
        public float $value,
        public ?string $unit,
        public ?float $goal,
        public ?string $note,
    ) {}

    /**
     * Value over goal (0.83 is 83 % of the goal), for a meter; null without
     * a positive goal. Not clamped: a figure may pass its goal.
     */
    public function ratio(): ?float
    {
        return $this->goal === null || $this->goal <= 0.0 ? null : $this->value / $this->goal;
    }
}
