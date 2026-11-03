<?php

namespace App\View\Home;

use App\Management\ManagementKpi;

/**
 * One figure of the «Panel gerencial» (App\Management\ManagementKpi),
 * ready to print in Colombian format:
 *
 * - pesos (`COP`): «$1.250» with «millones» from a million up, «$850.000» below;
 * - percent (`%`): «12,5 %»;
 * - anything else: the number, with its unit beside it («45 días»).
 *
 * With a positive goal it has a meter: «83 % de la meta» (a figure past its
 * goal says «120 %» and fills the meter) and «Meta: $1.500 millones».
 */
final readonly class KpiFigure
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $unit,
        public ?int $percentOfGoal,
        public ?string $goal,
        public ?string $note,
    ) {}

    public static function from(ManagementKpi $kpi): self
    {
        [$value, $unit] = self::format($kpi->value, $kpi->unit);
        $ratio = $kpi->ratio();
        $goal = null;

        if ($ratio !== null) {
            [$goalValue, $goalUnit] = self::format((float) $kpi->goal, $kpi->unit);
            $goal = 'Meta: '.$goalValue.($goalUnit === null ? '' : ' '.$goalUnit);
        }

        $note = trim((string) $kpi->note);

        return new self(
            $kpi->label,
            $value,
            $unit,
            $ratio === null ? null : (int) round($ratio * 100),
            $goal,
            $note === '' ? null : $note,
        );
    }

    /** The meter's fill, 0 to 100. */
    public function meter(): ?int
    {
        return $this->percentOfGoal === null ? null : max(0, min(100, $this->percentOfGoal));
    }

    /**
     * @return array{0: string, 1: string|null} The number and the unit printed small beside it.
     */
    private static function format(float $value, ?string $unit): array
    {
        $unit = trim((string) $unit);

        if (in_array(mb_strtoupper($unit), ['COP', '$'], true)) {
            $sign = $value < 0 ? '-' : '';
            $amount = abs($value);

            return $amount >= 1_000_000
                ? [$sign.'$'.self::number($amount / 1_000_000, $amount < 10_000_000 ? 1 : 0), 'millones']
                : [$sign.'$'.self::number($amount, 0), null];
        }

        if ($unit === '%') {
            return [self::number($value, 2).' %', null];
        }

        return [self::number($value, 2), $unit === '' ? null : $unit];
    }

    /** «1.250», «12,5»: up to $decimals decimals, without trailing zeros. */
    private static function number(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, ',', '.');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
