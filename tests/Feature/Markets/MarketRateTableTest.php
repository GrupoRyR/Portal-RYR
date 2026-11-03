<?php

namespace Tests\Feature\Markets;

use App\Models\MarketRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The `market_rates` table: one row per code and start date.
 */
class MarketRateTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_code_has_one_row_per_start_date(): void
    {
        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-03']);

        $this->expectException(QueryException::class);

        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-03']);
    }

    public function test_two_codes_can_share_a_start_date(): void
    {
        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-03']);
        MarketRate::factory()->eur()->create(['valid_from' => '2026-11-03']);

        $this->assertSame(2, MarketRate::query()->count());
    }

    public function test_every_index_name_fits_mysql(): void
    {
        $names = collect(Schema::getIndexes('market_rates'))->pluck('name');

        $this->assertNotEmpty($names);
        $this->assertContains('market_rates_code_from_unique', $names->all());

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }
    }

    public function test_the_value_keeps_four_decimals_and_the_dates_are_dates(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3182.9912', 'valid_from' => '2026-10-31', 'valid_until' => '2026-11-03']);

        $rate = MarketRate::query()->firstOrFail();

        $this->assertSame('3182.9912', $rate->value);
        $this->assertSame('2026-10-31', $rate->valid_from->toDateString());
        $this->assertSame('2026-11-03', $rate->valid_until->toDateString());
        $this->assertNotNull($rate->fetched_at);
    }

    public function test_the_end_date_is_optional(): void
    {
        MarketRate::factory()->eur()->create(['valid_until' => null]);

        $this->assertNull(MarketRate::query()->firstOrFail()->valid_until);
    }
}
