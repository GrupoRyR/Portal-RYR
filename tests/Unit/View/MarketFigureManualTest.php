<?php

namespace Tests\Unit\View;

use App\Markets\MarketQuote;
use App\Markets\MarketUnit;
use App\View\Home\MarketFigure;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * A price an admin typed in Administración › Mercados (App\Models\MarketInput)
 * is printed like the automatic figures: its own name, the value with its
 * unit («$4.200,00/kg»), the same arrows, colours and spoken phrase, and
 * the day it was quoted.
 */
class MarketFigureManualTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function price(string $label, MarketUnit $unit, float $value, ?float $change, ?float $percent, string $on = '2026-11-03'): MarketFigure
    {
        return MarketFigure::from(new MarketQuote(
            code: 'input-7',
            value: $value,
            validFrom: CarbonImmutable::parse($on),
            validUntil: null,
            change: $change,
            changePercent: $percent,
            label: $label,
            unit: $unit,
            manual: true,
        ));
    }

    public function test_a_price_in_pesos_per_kilo_keeps_its_name_unit_and_day(): void
    {
        $soda = $this->price('Soda cáustica', MarketUnit::PesosPerKilo, 4200, 100, 2.44);

        $this->assertSame('input-7', $soda->code);
        $this->assertSame('Soda cáustica', $soda->label);
        $this->assertSame('$4.200,00/kg', $soda->value);
        $this->assertSame('▲ 2,44 %', $soda->shortChange());
        $this->assertSame('good', $soda->tone());
        $this->assertSame('Subió 100,00 pesos por kilo (2,44 %)', $soda->spokenChange());
        $this->assertSame('2026-11-03', $soda->validFromIso);
        $this->assertSame('3 nov', $soda->validFromLabel);
        $this->assertSame('precio del 3 de noviembre de 2026', $soda->spokenDate());
    }

    public function test_every_manual_unit_prints_and_speaks_its_own_unit(): void
    {
        $expected = [
            'cop_kg' => ['$10,00/kg', 'pesos por kilo'],
            'cop_unit' => ['$10,00/u', 'pesos por unidad'],
            'cop_t' => ['$10,00/t', 'pesos por tonelada'],
            'usd_kg' => ['US$10,00/kg', 'dólares por kilo'],
            'usd_tonne' => ['US$10,00/t', 'dólares por tonelada'],
        ];

        $this->assertSame(array_keys($expected), MarketUnit::manualValues());

        foreach ($expected as $unit => [$value, $words]) {
            $figure = $this->price('Insumo', MarketUnit::from($unit), 10, -1, -9.09);

            $this->assertSame($value, $figure->value, $unit);
            $this->assertSame("Bajó 1,00 {$words} (9,09 %)", $figure->spokenChange(), $unit);
            $this->assertSame('crit', $figure->tone(), $unit);
        }
    }

    public function test_a_first_price_shows_no_change_and_an_equal_one_is_flat(): void
    {
        $first = $this->price('PET', MarketUnit::PesosPerKilo, 5100, null, null);
        $this->assertNull($first->shortChange());
        $this->assertNull($first->spokenChange());

        $same = $this->price('PET', MarketUnit::PesosPerKilo, 5100, 0.0, 0.0);
        $this->assertSame('● 0,00 %', $same->shortChange());
        $this->assertSame('flat', $same->tone());
    }

    public function test_a_price_from_another_year_shows_the_year(): void
    {
        $old = $this->price('HDPE', MarketUnit::UsdPerTonne, 1450, null, null, '2025-12-15');

        $this->assertSame('15 dic 2025', $old->validFromLabel);
        $this->assertSame('precio del 15 de diciembre de 2025', $old->spokenDate());
    }

    public function test_the_same_unit_in_the_catalogue_carries_no_suffix(): void
    {
        // Zinc (US$/t) has its unit in its label.
        $zinc = MarketFigure::from(new MarketQuote('zinc', 2845.5, CarbonImmutable::parse('2026-09-01'), null, null, null));

        $this->assertSame('US$2.845,50', $zinc->value);
    }
}
