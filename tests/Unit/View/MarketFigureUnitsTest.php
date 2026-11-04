<?php

namespace Tests\Unit\View;

use App\Markets\MarketQuote;
use App\View\Home\MarketFigure;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Each figure of the band is printed and spoken in the unit of its series
 * (App\Markets\MarketSeries): pesos, dollars, percent, points or an index,
 * with the day of a daily series or the month of a monthly one.
 */
class MarketFigureUnitsTest extends TestCase
{
    private function figure(string $code, float $value, ?float $change, ?float $percent, string $from = '2026-11-03'): MarketFigure
    {
        return MarketFigure::from(new MarketQuote(
            code: $code,
            value: $value,
            validFrom: CarbonImmutable::parse($from),
            validUntil: null,
            change: $change,
            changePercent: $percent,
        ));
    }

    public function test_the_trm_stays_in_pesos_with_its_day(): void
    {
        $trm = $this->figure('trm', 3861.38, 12.4, 0.32);

        $this->assertSame('Dólar (TRM)', $trm->label);
        $this->assertSame('$3.861,38', $trm->value);
        $this->assertSame('▲ 0,32 %', $trm->shortChange());
        $this->assertSame('Subió 12,40 pesos (0,32 %)', $trm->spokenChange());
        $this->assertSame('2026-11-03', $trm->validFromIso);
        $this->assertSame('3 nov', $trm->validFromLabel);
        $this->assertSame('vigente el', $trm->datePrefix());
    }

    public function test_brent_is_in_dollars_per_barrel(): void
    {
        $brent = $this->figure('brent', 64.53, -1.2, -1.83, '2026-10-27');

        $this->assertSame('US$64,53', $brent->value);
        $this->assertSame('▼ 1,83 %', $brent->shortChange());
        $this->assertSame('Bajó 1,20 dólares por barril (1,83 %)', $brent->spokenChange());
        $this->assertSame('27 oct', $brent->validFromLabel);
        $this->assertSame('crit', $brent->tone());
    }

    public function test_a_metal_is_in_dollars_per_tonne_and_shows_its_month(): void
    {
        $zinc = $this->figure('zinc', 2845.5, 45, 1.61, '2026-09-01');

        $this->assertSame('Zinc (US$/t)', $zinc->label);
        $this->assertSame('US$2.845,50', $zinc->value);
        $this->assertSame('Subió 45,00 dólares por tonelada (1,61 %)', $zinc->spokenChange());
        $this->assertSame('2026-09', $zinc->validFromIso);
        $this->assertSame('sep 2026', $zinc->validFromLabel);
        $this->assertSame('dato de', $zinc->datePrefix());
        $this->assertSame('good', $zinc->tone());
    }

    public function test_the_policy_rate_is_a_percent_that_moves_in_percentage_points(): void
    {
        $tpm = $this->figure('tpm', 9.25, -0.25, -2.63);

        $this->assertSame('9,25 %', $tpm->value);
        $this->assertNull($tpm->percent);
        $this->assertSame('▼ 0,25 pp', $tpm->shortChange());
        $this->assertSame('Bajó 0,25 puntos porcentuales', $tpm->spokenChange());

        $still = $this->figure('tpm', 9.25, 0.0, 0.0);
        $this->assertSame('● 0,00 pp', $still->shortChange());
        $this->assertSame('Sin cambio frente al valor anterior', $still->spokenChange());
    }

    public function test_colcap_is_in_points_with_its_month(): void
    {
        $colcap = $this->figure('colcap', 1512.3, 12.4, 0.83, '2026-10-01');

        $this->assertSame('1.512,30', $colcap->value);
        $this->assertSame('▲ 0,83 %', $colcap->shortChange());
        $this->assertSame('Subió 12,40 puntos (0,83 %)', $colcap->spokenChange());
        $this->assertSame('oct 2026', $colcap->validFromLabel);
    }

    public function test_an_index_shows_its_monthly_change_in_percent(): void
    {
        $brass = $this->figure('brass', 312.456, 2.5, 0.81, '2026-09-01');

        $this->assertSame('Latón (índice)', $brass->label);
        $this->assertSame('312,46', $brass->value);
        $this->assertStringNotContainsString('$', $brass->value);
        $this->assertSame('▲ 0,81 %', $brass->shortChange());
        $this->assertSame('Subió 0,81 % frente al mes anterior', $brass->spokenChange());
        $this->assertSame('sep 2026', $brass->validFromLabel);
    }

    public function test_every_known_code_has_its_own_spoken_unit(): void
    {
        foreach (['trm', 'eur', 'brent', 'tpm', 'colcap', 'zinc', 'copper', 'aluminum', 'brass', 'steel', 'alkali', 'resins', 'bottles'] as $code) {
            $spoken = $this->figure($code, 100.0, 1.0, 1.0, '2026-09-01')->spokenChange();

            $this->assertNotNull($spoken, $code);
            $this->assertStringNotContainsString(MarketFigure::FALLBACK_UNIT.' (', $code === 'colcap' ? '' : $spoken, $code);
        }
    }

    public function test_an_unknown_code_falls_back_to_points(): void
    {
        $figure = $this->figure('m9', 10.0, -1.0, -9.09);

        $this->assertSame('M9', $figure->label);
        $this->assertSame('Bajó 1,00 puntos (9,09 %)', $figure->spokenChange());
    }
}
