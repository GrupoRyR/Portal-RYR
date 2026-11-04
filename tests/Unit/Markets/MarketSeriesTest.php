<?php

namespace Tests\Unit\Markets;

use App\Markets\MarketSeries;
use App\Markets\MarketUnit;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of market series (odd/tasks/mercados-o5.md M1): every code
 * the band can show, with its label, unit, source, frequency and scope.
 */
class MarketSeriesTest extends TestCase
{
    public function test_the_catalogue_lists_the_series_for_everyone_first_then_the_inputs(): void
    {
        $this->assertSame(
            ['trm', 'eur', 'brent', 'tpm', 'colcap', 'zinc', 'copper', 'aluminum', 'brass', 'steel', 'alkali', 'resins', 'bottles'],
            MarketSeries::codes(),
        );
        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap'], MarketSeries::codesFor([]));
        $this->assertSame(['zinc', 'copper', 'aluminum', 'brass', 'steel', 'alkali', 'resins', 'bottles'], MarketSeries::inputCodes());
    }

    public function test_every_code_fits_the_column_and_every_series_is_complete(): void
    {
        foreach (MarketSeries::all() as $code => $series) {
            $this->assertSame($code, $series->code);
            $this->assertLessThanOrEqual(8, strlen($code), $code);
            $this->assertMatchesRegularExpression('/\A[a-z]+\z/', $code);
            $this->assertNotSame('', $series->label, $code);
            $this->assertLessThanOrEqual(40, strlen($series->source), $code);
            $this->assertContains($series->source, [MarketSeries::SOURCE_TRM, MarketSeries::SOURCE_ECB, MarketSeries::SOURCE_BANREP, MarketSeries::SOURCE_FRED], $code);
            $this->assertContains($series->frequency, [MarketSeries::DAILY, MarketSeries::MONTHLY], $code);
            $this->assertContains($series->scope, [MarketSeries::SCOPE_ALL, MarketSeries::SCOPE_INPUT], $code);

            // The automatic sources need the id of the series they read.
            if (in_array($series->source, [MarketSeries::SOURCE_BANREP, MarketSeries::SOURCE_FRED], true)) {
                $this->assertNotNull($series->sourceKey, $code);
            }
        }
    }

    public function test_the_units_and_sources_of_the_new_series(): void
    {
        $this->assertSame(MarketUnit::Pesos, MarketSeries::find('trm')->unit);
        $this->assertSame(MarketUnit::UsdPerBarrel, MarketSeries::find('brent')->unit);
        $this->assertSame('DCOILBRENTEU', MarketSeries::find('brent')->sourceKey);
        $this->assertSame(MarketUnit::Percent, MarketSeries::find('tpm')->unit);
        $this->assertSame('DF_CBR_DAILY_HIST', MarketSeries::find('tpm')->sourceKey);
        $this->assertSame(MarketUnit::Points, MarketSeries::find('colcap')->unit);
        $this->assertTrue(MarketSeries::find('colcap')->isMonthly());
        $this->assertSame(MarketUnit::UsdPerTonne, MarketSeries::find('zinc')->unit);
        $this->assertSame(MarketUnit::Index, MarketSeries::find('bottles')->unit);
        $this->assertSame('PCU326160326160', MarketSeries::find('bottles')->sourceKey);
        $this->assertFalse(MarketSeries::find('brent')->isMonthly());
        $this->assertNull(MarketSeries::find('m9'));
    }

    public function test_only_known_inputs_survive_a_selection_in_catalogue_order(): void
    {
        $this->assertSame(
            ['zinc', 'brass', 'bottles'],
            MarketSeries::onlyInputs(['bottles', 'trm', 'zinc', 'nope', 'zinc', 7, null, ['x'], 'brass', ' ZINC ']),
        );
        $this->assertSame([], MarketSeries::onlyInputs(null));
        $this->assertSame([], MarketSeries::onlyInputs('zinc'));
        $this->assertSame([], MarketSeries::onlyInputs([]));
    }

    public function test_the_band_of_a_selection_is_everyones_series_then_its_inputs(): void
    {
        $this->assertSame(
            ['trm', 'eur', 'brent', 'tpm', 'colcap', 'copper', 'resins'],
            MarketSeries::codesFor(['resins', 'eur', 'copper']),
        );
    }
}
