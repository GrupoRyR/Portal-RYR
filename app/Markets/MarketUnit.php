<?php

namespace App\Markets;

/**
 * What a market value measures (App\Markets\MarketSeries): it decides how
 * the band prints the value and how a screen reader hears its change
 * (App\View\Home\MarketFigure).
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
        };
    }
}
