<?php

namespace App\Markets;

/**
 * What a market value measures (App\Markets\MarketSeries): it decides how
 * the band prints the value and how a screen reader hears its change
 * (App\View\Home\MarketFigure).
 *
 * The per-kilo, per-unit and per-tonne units are those an admin may give a
 * price typed by hand (App\Models\MarketInput, manualOptions()); dollars per
 * tonne serves both. A manual price has no unit in its name, so the band
 * prints it after the value («$4.200,00/kg», suffix()).
 */
enum MarketUnit: string
{
    /** Colombian pesos per unit (the TRM, the euro): «$3.861,38». */
    case Pesos = 'pesos';

    /** US dollars per barrel (Brent): «US$64,53». */
    case UsdPerBarrel = 'usd_barrel';

    /** US dollars per metric tonne (metals): «US$2.845,50». */
    case UsdPerTonne = 'usd_tonne';

    /** A rate in percent (the policy rate): «9,25 %», moves in percentage points. */
    case Percent = 'percent';

    /** Index points of a stock index (COLCAP): «1.512,30». */
    case Points = 'points';

    /** A producer price index (base 100 at some past date): its level, read with its monthly change in %. */
    case Index = 'index';

    /** Colombian pesos per kilo (a manual price): «$4.200,00/kg». */
    case PesosPerKilo = 'cop_kg';

    /** Colombian pesos per unit (a manual price): «$1.350,00/u». */
    case PesosPerUnit = 'cop_unit';

    /** Colombian pesos per tonne (a manual price): «$4.200.000,00/t». */
    case PesosPerTonne = 'cop_t';

    /** US dollars per kilo (a manual price): «US$7,50/kg». */
    case UsdPerKilo = 'usd_kg';

    /**
     * The units a manual price may have, in the order the form offers
     * them, with their Spanish names.
     */
    private const MANUAL = [
        'cop_kg' => 'Pesos por kilo ($/kg)',
        'cop_unit' => 'Pesos por unidad ($/u)',
        'cop_t' => 'Pesos por tonelada ($/t)',
        'usd_kg' => 'Dólares por kilo (US$/kg)',
        'usd_tonne' => 'Dólares por tonelada (US$/t)',
    ];

    /**
     * @return list<string> The values a manual price may store, in form order.
     */
    public static function manualValues(): array
    {
        return array_keys(self::MANUAL);
    }

    /**
     * @return array<string, string> Each manual unit with its Spanish name, in form order.
     */
    public static function manualOptions(): array
    {
        return self::MANUAL;
    }

    /**
     * The Spanish name of the unit: «Pesos por kilo ($/kg)» for a manual
     * one, «Dólares por barril» or «Índice» for a series of the catalogue.
     */
    public function label(): string
    {
        return self::MANUAL[$this->value] ?? match ($this) {
            self::Pesos => 'Pesos',
            self::UsdPerBarrel => 'Dólares por barril',
            self::Percent => 'Porcentaje',
            self::Points => 'Puntos',
            default => 'Índice',
        };
    }

    /**
     * The value in this unit without its «per»: «$3.861,38», «US$64,53»,
     * «9,25 %» or «1.512,30».
     */
    public function format(float $value): string
    {
        return match ($this) {
            self::UsdPerBarrel, self::UsdPerTonne, self::UsdPerKilo => 'US$'.self::number($value),
            self::Percent => self::number($value).' %',
            self::Points, self::Index => self::number($value),
            self::Pesos, self::PesosPerKilo, self::PesosPerUnit, self::PesosPerTonne => '$'.self::number($value),
        };
    }

    /**
     * What a manual price is per, printed after its value: «/kg», «/u» or
     * «/t». Empty for the others (their name says it: «Zinc (US$/t)»).
     */
    public function suffix(): string
    {
        return match ($this) {
            self::PesosPerKilo, self::UsdPerKilo => '/kg',
            self::PesosPerUnit => '/u',
            self::PesosPerTonne, self::UsdPerTonne => '/t',
            default => '',
        };
    }

    /** «3.861,38»: Colombian thousands and decimals. */
    public static function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    /**
     * The words a screen reader hears after the amount of a change
     * («Subió 12,40 pesos»). Null for an index: the band prints its level,
     * but its change is spoken in percent only.
     */
    public function spokenAmount(): ?string
    {
        return match ($this) {
            self::Pesos => 'pesos',
            self::UsdPerBarrel => 'dólares por barril',
            self::UsdPerTonne => 'dólares por tonelada',
            self::Percent => 'puntos porcentuales',
            self::Points => 'puntos',
            self::Index => null,
            self::PesosPerKilo => 'pesos por kilo',
            self::PesosPerUnit => 'pesos por unidad',
            self::PesosPerTonne => 'pesos por tonelada',
            self::UsdPerKilo => 'dólares por kilo',
        };
    }
}
