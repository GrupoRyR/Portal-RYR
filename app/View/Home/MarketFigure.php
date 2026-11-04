<?php

namespace App\View\Home;

use App\Markets\MarketQuote;
use App\Markets\MarketSeries;
use App\Markets\MarketUnit;

/**
 * One figure of the «Mercados» band of the home (App\Markets\MarketBoard),
 * ready to print: «Dólar (TRM) $3.861,38 ▲ 0,32 %», with the sentence a
 * screen reader hears instead of the arrow.
 *
 * The label, the format and the spoken words come from the unit of the
 * series (App\Markets\MarketSeries, App\Markets\MarketUnit):
 *
 * - pesos «$3.861,38», dollars «US$64,53» (per barrel or per tonne, in the
 *   label), points «1.512,30»: change in the unit and in percent;
 * - percent «9,25 %»: change in percentage points («▲ 0,25 pp»);
 * - index «312,46»: change in percent only, the index level means little.
 *
 * A daily series shows its day («3 nov»), a monthly one its month
 * («sep 2026»).
 */
final readonly class MarketFigure
{
    /** The unit a screen reader hears for a code outside the catalogue. */
    public const FALLBACK_UNIT = 'puntos';

    /** A change (in the code's unit, or in percent) below this prints as none. */
    public const NO_CHANGE = 0.005;

    /**
     * @param  'up'|'down'|'flat'|null  $direction  null when there is no previous value
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $value,
        public ?string $direction,
        public ?string $percent,
        public ?string $change,
        public string $validFromIso,
        public string $validFromLabel,
    ) {}

    public static function from(MarketQuote $quote): self
    {
        $series = MarketSeries::find($quote->code);
        $unit = $series?->unit;

        // Under half a cent (or a percent that prints as 0,00) is no move:
        // it would show an arrow beside «0,00 %».
        $direction = match (true) {
            $quote->change === null => null,
            abs($quote->change) < self::NO_CHANGE || ($quote->changePercent !== null && abs($quote->changePercent) < self::NO_CHANGE) => 'flat',
            $quote->change > 0 => 'up',
            default => 'down',
        };

        $monthly = $series?->isMonthly() === true;

        return new self(
            code: $quote->code,
            label: $series->label ?? strtoupper($quote->code),
            value: self::value($quote->value, $unit),
            direction: $direction,
            // A rate moves in percentage points: a percent of a percent only confuses.
            percent: $quote->changePercent === null || $unit === MarketUnit::Percent ? null : self::number(abs($quote->changePercent)).' %',
            change: $quote->change === null ? null : self::number(abs($quote->change)),
            // «2026-09» is a valid <time> month.
            validFromIso: $monthly ? $quote->validFrom->format('Y-m') : $quote->validFrom->toDateString(),
            validFromLabel: str_replace('.', '', $quote->validFrom->locale('es')->isoFormat($monthly ? 'MMM YYYY' : 'D MMM')),
        );
    }

    /**
     * «▲ 0,32 %» beside the value (hidden from screen readers), «▲ 0,25 pp»
     * for a rate, «● 0,00 %» without a move, or null.
     */
    public function shortChange(): ?string
    {
        if ($this->unit() === MarketUnit::Percent) {
            return match ($this->direction) {
                'up' => "▲ {$this->change} pp",
                'down' => "▼ {$this->change} pp",
                'flat' => '● 0,00 pp',
                default => null,
            };
        }

        return match ($this->direction) {
            'up' => '▲ '.($this->percent ?? $this->change),
            'down' => '▼ '.($this->percent ?? $this->change),
            'flat' => '● 0,00'.($this->percent === null ? '' : ' %'),
            default => null,
        };
    }

    /**
     * The colour class of the change: «good» (green) for a rise, «crit»
     * (red) for a fall, «flat» (neutral) without a move, or null.
     */
    public function tone(): ?string
    {
        return match ($this->direction) {
            'up' => 'good',
            'down' => 'crit',
            'flat' => 'flat',
            default => null,
        };
    }

    /**
     * «Subió 12,40 pesos (0,32 %)» for screen readers, in the unit of the
     * code: «Bajó 0,25 puntos porcentuales» for a rate, «Subió 0,81 %
     * frente al mes anterior» for an index. Null without a change.
     */
    public function spokenChange(): ?string
    {
        $verb = match ($this->direction) {
            'up' => 'Subió',
            'down' => 'Bajó',
            'flat' => 'Sin cambio',
            default => null,
        };

        if ($verb === null) {
            return null;
        }

        if ($this->direction === 'flat') {
            return 'Sin cambio frente al valor anterior';
        }

        $unit = $this->unit();

        if ($unit === MarketUnit::Index && $this->percent !== null) {
            return "{$verb} {$this->percent} frente al ".($this->isMonthly() ? 'mes anterior' : 'valor anterior');
        }

        $words = $unit?->spokenAmount() ?? self::FALLBACK_UNIT;
        $percent = $this->percent === null ? '' : " ({$this->percent})";

        return "{$verb} {$this->change} {$words}{$percent}";
    }

    /**
     * What a screen reader hears before the date: the day a daily value is
     * valid, or the month a monthly value belongs to.
     */
    public function datePrefix(): string
    {
        return $this->isMonthly() ? 'dato de' : 'vigente el';
    }

    private function unit(): ?MarketUnit
    {
        return MarketSeries::find($this->code)?->unit;
    }

    private function isMonthly(): bool
    {
        return MarketSeries::find($this->code)?->isMonthly() === true;
    }

    /** The value in its unit: «$3.861,38», «US$64,53», «9,25 %» or «1.512,30». */
    private static function value(float $value, ?MarketUnit $unit): string
    {
        return match ($unit) {
            MarketUnit::UsdPerBarrel, MarketUnit::UsdPerTonne => 'US$'.self::number($value),
            MarketUnit::Percent => self::number($value).' %',
            MarketUnit::Points, MarketUnit::Index => self::number($value),
            // Pesos, and a code outside the catalogue as before.
            default => '$'.self::number($value),
        };
    }

    /** «3.861,38»: Colombian thousands and decimals. */
    private static function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
