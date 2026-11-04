<?php

namespace App\View\Home;

use App\Markets\MarketQuote;
use App\Models\MarketRate;

/**
 * One figure of the «Mercados» band of the home (App\Markets\MarketBoard),
 * ready to print: «Dólar (TRM) $3.861,38 ▲ 0,32 %», with the sentence a
 * screen reader hears instead of the arrow.
 */
final readonly class MarketFigure
{
    public const LABELS = [
        MarketRate::TRM => 'Dólar (TRM)',
        MarketRate::EUR => 'Euro',
    ];

    /**
     * The unit a screen reader hears with the change: the TRM and the euro
     * are pesos per unit; codes priced in dollars (Brent, when it is
     * synced) say dólares; any other code says puntos.
     */
    public const UNITS = [
        MarketRate::TRM => 'pesos',
        MarketRate::EUR => 'pesos',
        'brent' => 'dólares',
    ];

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
        // Under half a cent (or a percent that prints as 0,00) is no move:
        // it would show an arrow beside «0,00 %».
        $direction = match (true) {
            $quote->change === null => null,
            abs($quote->change) < self::NO_CHANGE || ($quote->changePercent !== null && abs($quote->changePercent) < self::NO_CHANGE) => 'flat',
            $quote->change > 0 => 'up',
            default => 'down',
        };

        return new self(
            code: $quote->code,
            label: self::LABELS[$quote->code] ?? strtoupper($quote->code),
            value: '$'.self::number($quote->value),
            direction: $direction,
            percent: $quote->changePercent === null ? null : self::number(abs($quote->changePercent)).' %',
            change: $quote->change === null ? null : self::number(abs($quote->change)),
            validFromIso: $quote->validFrom->toDateString(),
            validFromLabel: str_replace('.', '', $quote->validFrom->locale('es')->isoFormat('D MMM')),
        );
    }

    /**
     * «▲ 0,32 %» beside the value (hidden from screen readers), «● 0,00 %»
     * without a move, or null.
     */
    public function shortChange(): ?string
    {
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
     * code (UNITS), or null.
     */
    public function spokenChange(): ?string
    {
        $percent = $this->percent === null ? '' : " ({$this->percent})";
        $unit = self::UNITS[$this->code] ?? self::FALLBACK_UNIT;

        return match ($this->direction) {
            'up' => "Subió {$this->change} {$unit}{$percent}",
            'down' => "Bajó {$this->change} {$unit}{$percent}",
            'flat' => 'Sin cambio frente al valor anterior',
            default => null,
        };
    }

    /** «3.861,38»: Colombian thousands and decimals. */
    private static function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
