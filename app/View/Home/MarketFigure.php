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
        $direction = match (true) {
            $quote->change === null => null,
            $quote->change > 0 => 'up',
            $quote->change < 0 => 'down',
            default => 'flat',
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
     * «▲ 0,32 %» beside the value (hidden from screen readers), or null.
     */
    public function shortChange(): ?string
    {
        return match ($this->direction) {
            'up' => '▲ '.($this->percent ?? $this->change),
            'down' => '▼ '.($this->percent ?? $this->change),
            'flat' => 'Sin cambio',
            default => null,
        };
    }

    /**
     * «Subió 12,40 pesos (0,32 %)» for screen readers, or null.
     */
    public function spokenChange(): ?string
    {
        $percent = $this->percent === null ? '' : " ({$this->percent})";

        return match ($this->direction) {
            'up' => "Subió {$this->change} pesos{$percent}",
            'down' => "Bajó {$this->change} pesos{$percent}",
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
