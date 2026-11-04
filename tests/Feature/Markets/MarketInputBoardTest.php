<?php

namespace Tests\Feature\Markets;

use App\Markets\MarketBoard;
use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use App\Models\MarketRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The prices an admin types by hand (`market_inputs`, odd/tasks/mercados-o5.md
 * M4) ride in the same query as the automatic series: after them, the
 * latest price of each name of the company with its change against the
 * previous price of that name.
 */
class MarketInputBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota'));
        $this->company = Company::factory()->withMarketCodes(['zinc'])->create();
    }

    public function test_the_latest_price_of_each_name_comes_after_the_series_with_its_change(): void
    {
        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-04']);
        MarketRate::factory()->create(['code' => 'zinc', 'valid_from' => '2026-09-01', 'source' => 'fred.stlouisfed.org']);
        $this->price('Soda cáustica', '4100', '2026-10-01');
        $latest = $this->price('Soda cáustica', '4200', '2026-11-03');
        $this->price('Soda cáustica', '3900', '2026-08-01');
        $pet = $this->price('PET', '5100', '2026-10-20', MarketUnit::UsdPerTonne);

        $board = (new MarketBoard)->current($this->company);

        $this->assertSame(['trm', 'zinc', "input-{$pet->id}", "input-{$latest->id}"], array_keys($board));

        $soda = $board["input-{$latest->id}"];
        $this->assertTrue($soda->manual);
        $this->assertSame('Soda cáustica', $soda->label);
        $this->assertSame(MarketUnit::PesosPerKilo, $soda->unit);
        $this->assertEqualsWithDelta(4200, $soda->value, 0.00001);
        $this->assertSame('2026-11-03', $soda->validFrom->toDateString());
        $this->assertEqualsWithDelta(100, $soda->change, 0.00001);
        $this->assertEqualsWithDelta(2.44, $soda->changePercent, 0.00001);

        $first = $board["input-{$pet->id}"];
        $this->assertSame(MarketUnit::UsdPerTonne, $first->unit);
        $this->assertNull($first->change);
    }

    public function test_two_prices_on_the_same_day_keep_the_last_one_entered(): void
    {
        $this->price('Cloro', '1000', '2026-11-02');
        $newer = $this->price('Cloro', '1100', '2026-11-02');

        $board = (new MarketBoard)->current($this->company);

        $this->assertSame(["input-{$newer->id}"], array_keys($board));
        $this->assertEqualsWithDelta(100, $board["input-{$newer->id}"]->change, 0.00001);
    }

    public function test_a_price_in_another_unit_is_not_compared(): void
    {
        $this->price('Latón', '30000', '2026-10-01', MarketUnit::PesosPerKilo);
        $latest = $this->price('Latón', '7.5', '2026-11-01', MarketUnit::UsdPerKilo);

        $quote = (new MarketBoard)->current($this->company)["input-{$latest->id}"];

        $this->assertNull($quote->change);
        $this->assertNull($quote->changePercent);
    }

    public function test_only_the_company_s_own_prices_and_none_without_a_company(): void
    {
        $other = Company::factory()->create();
        $mine = $this->price('Soda cáustica', '4200', '2026-11-03');
        MarketInput::factory()->for($other)->create(['label' => 'Ácido', 'valid_on' => '2026-11-03']);

        $this->assertSame(["input-{$mine->id}"], array_keys((new MarketBoard)->current($this->company)));
        $this->assertSame([], (new MarketBoard)->current());
    }

    public function test_a_price_older_than_the_window_is_not_read(): void
    {
        $this->price('Soda cáustica', '4200', now('America/Bogota')->subDays(MarketBoard::WINDOW_DAYS + 1)->toDateString());

        $this->assertSame([], (new MarketBoard)->current($this->company));
    }

    public function test_the_prices_add_no_query(): void
    {
        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-04']);
        foreach (['Soda cáustica', 'PET', 'HDPE'] as $i => $label) {
            $this->price($label, '100', '2026-10-0'.($i + 1));
            $this->price($label, '110', '2026-11-0'.($i + 1));
        }

        DB::enableQueryLog();
        $board = (new MarketBoard)->current($this->company);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount(4, $board);
    }

    private function price(string $label, string $value, string $on, MarketUnit $unit = MarketUnit::PesosPerKilo): MarketInput
    {
        return MarketInput::factory()->for($this->company)->create([
            'label' => $label,
            'unit' => $unit,
            'value' => $value,
            'valid_on' => $on,
        ]);
    }
}
