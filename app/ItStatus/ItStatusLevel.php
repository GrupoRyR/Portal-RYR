<?php

namespace App\ItStatus;

use App\Models\ItService;

/**
 * How bad things are, for one item of «Estado de TI» or for the whole
 * board: the worst of its items.
 */
enum ItStatusLevel: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Down = 'down';

    /**
     * The status of a manual service (App\Models\ItService) or of a report
     * from an external system (App\Models\App::EXTERNAL_STATUSES). Anything
     * unknown counts as a partial failure, never as fine.
     */
    public static function fromStatus(?string $status): self
    {
        return self::tryFrom((string) $status) ?? self::Degraded;
    }

    /**
     * @param  iterable<self>  $levels
     */
    public static function worst(iterable $levels): self
    {
        $worst = self::Operational;

        foreach ($levels as $level) {
            if ($level->severity() > $worst->severity()) {
                $worst = $level;
            }
        }

        return $worst;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }

    /**
     * Headline of the whole board.
     */
    public function headline(): string
    {
        return match ($this) {
            self::Operational => 'Todo funciona con normalidad',
            self::Degraded => 'Algunos servicios tienen fallas',
            self::Down => 'Hay servicios fuera de servicio',
        };
    }

    /**
     * Chip of a failing item at this level, service or app report: «Con
     * fallas» or «Fuera de servicio» (the Spanish names of ItService).
     */
    public function label(): string
    {
        return ItService::statusLabel($this->value);
    }
}
