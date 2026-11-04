<?php

namespace Tests\Feature\Markets;

use App\Markets\MarketBoard;
use App\Markets\MarketSeries;
use App\Models\Company;
use App\Models\MarketRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The read model of the markets band: latest value, its validity and the
 * change against the previous one, per code, in one query.
 */
class MarketBoardTest extends TestCase
{
    use RefreshDatabase;

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpTimezone = date_default_timezone_get();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'America/Bogota'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);

        parent::tearDown();
    }

    public function test_it_gives_the_latest_value_and_the_change_for_each_code(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3200.0000', 'valid_from' => '2026-10-30', 'valid_until' => '2026-10-30']);
        MarketRate::factory()->trm()->create(['value' => '3182.9900', 'valid_from' => '2026-10-31', 'valid_until' => '2026-11-03']);
        MarketRate::factory()->eur()->create(['value' => '3400.0000', 'valid_from' => '2026-11-02', 'valid_until' => null]);
        MarketRate::factory()->eur()->create(['value' => '3437.6292', 'valid_from' => '2026-11-03', 'valid_until' => null]);

        $board = (new MarketBoard)->current();

        $this->assertSame(['trm', 'eur'], array_keys($board));

        $trm = $board['trm'];
        $this->assertSame('trm', $trm->code);
        $this->assertEqualsWithDelta(3182.99, $trm->value, 0.00001);
        $this->assertSame('2026-10-31', $trm->validFrom->toDateString());
        $this->assertSame('2026-11-03', $trm->validUntil?->toDateString());
        $this->assertEqualsWithDelta(-17.01, $trm->change, 0.00001);
        $this->assertEqualsWithDelta(-0.53, $trm->changePercent, 0.00001);

        $eur = $board['eur'];
        $this->assertEqualsWithDelta(3437.6292, $eur->value, 0.00001);
        $this->assertNull($eur->validUntil);
        $this->assertEqualsWithDelta(37.6292, $eur->change, 0.00001);
        $this->assertEqualsWithDelta(1.11, $eur->changePercent, 0.00001);
    }

    public function test_a_code_with_one_value_has_no_change(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3182.9900', 'valid_from' => '2026-10-31']);

        $trm = (new MarketBoard)->current()['trm'];

        $this->assertNull($trm->change);
        $this->assertNull($trm->changePercent);
    }

    public function test_a_value_that_starts_after_today_in_bogota_is_not_the_latest(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3100.0000', 'valid_from' => '2026-10-30']);
        MarketRate::factory()->trm()->create(['value' => '3182.9900', 'valid_from' => '2026-11-03']);
        MarketRate::factory()->trm()->create(['value' => '9999.0000', 'valid_from' => '2026-11-04']);

        $trm = (new MarketBoard)->current()['trm'];

        $this->assertEqualsWithDelta(3182.99, $trm->value, 0.00001);
        $this->assertEqualsWithDelta(82.99, $trm->change, 0.00001);
    }

    public function test_today_is_the_date_in_bogota_not_in_utc(): void
    {
        // 22:00 in Bogotá on 3 Nov is already 4 Nov in UTC, and the app and
        // PHP run in UTC here, so only the Bogotá date can pass this test.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-03 22:00:00', 'America/Bogota'));
        MarketRate::factory()->trm()->create(['value' => '3182.9900', 'valid_from' => '2026-11-03']);
        MarketRate::factory()->trm()->create(['value' => '9999.0000', 'valid_from' => '2026-11-04']);

        $this->assertEqualsWithDelta(3182.99, (new MarketBoard)->current()['trm']->value, 0.00001);
    }

    public function test_a_code_without_values_is_left_out(): void
    {
        MarketRate::factory()->eur()->create(['valid_from' => '2026-11-03']);

        $this->assertSame(['eur'], array_keys((new MarketBoard)->current()));
        $this->assertSame([], (function () {
            MarketRate::query()->delete();

            return (new MarketBoard)->current();
        })());
    }

    public function test_it_reads_the_table_in_one_query(): void
    {
        foreach (range(0, 11) as $day) {
            $date = Carbon::parse('2026-10-20')->addDays($day)->toDateString();
            MarketRate::factory()->trm()->create(['valid_from' => $date]);
            MarketRate::factory()->eur()->create(['valid_from' => $date]);
        }

        DB::enableQueryLog();
        $board = (new MarketBoard)->current();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $board);
        $this->assertCount(1, $queries, 'The board must read both codes in a single query.');
    }

    /**
     * One row of every code of the catalogue, valid on 3 Nov.
     */
    private function everyCode(): void
    {
        foreach (MarketSeries::codes() as $code) {
            MarketRate::factory()->create(['code' => $code, 'value' => '100', 'valid_from' => '2026-11-01']);
        }
    }

    public function test_without_a_company_or_a_selection_only_the_series_for_everyone_show(): void
    {
        $this->everyCode();

        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap'], array_keys((new MarketBoard)->current()));
        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap'], array_keys((new MarketBoard)->current(Company::factory()->create())));
        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap'], array_keys((new MarketBoard)->current(Company::factory()->create(['market_codes' => []]))));
    }

    public function test_a_company_sees_its_chosen_inputs_after_the_series_for_everyone(): void
    {
        $this->everyCode();
        $company = Company::factory()->withMarketCodes(['bottles', 'zinc'])->create();

        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap', 'zinc', 'bottles'], array_keys((new MarketBoard)->current($company)));
    }

    public function test_another_company_does_not_see_those_inputs(): void
    {
        $this->everyCode();
        Company::factory()->withMarketCodes(['zinc', 'copper'])->create();
        $other = Company::factory()->withMarketCodes(['resins'])->create();

        $this->assertSame(['trm', 'eur', 'brent', 'tpm', 'colcap', 'resins'], array_keys((new MarketBoard)->current($other)));
    }

    public function test_a_selection_still_reads_the_table_in_one_query(): void
    {
        $this->everyCode();
        $company = Company::factory()->withMarketCodes(['zinc', 'copper', 'aluminum', 'brass', 'steel', 'alkali', 'resins', 'bottles'])->create()->fresh();

        DB::enableQueryLog();
        $board = (new MarketBoard)->current($company);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(13, $board);
        $this->assertCount(1, $queries);
    }

    // --- The policy rate: against its last decision -----------------------------

    private function rate(string $date, string $value): void
    {
        MarketRate::factory()->create(['code' => 'tpm', 'value' => $value, 'valid_from' => $date, 'source' => 'banrep.gov.co']);
    }

    public function test_the_policy_rate_moves_against_the_last_different_rate_not_yesterday(): void
    {
        $this->rate('2026-10-29', '12.0000');
        $this->rate('2026-10-30', '12.5000');
        $this->rate('2026-10-31', '12.5000');
        $this->rate('2026-11-01', '12.2500');
        $this->rate('2026-11-02', '12.2500');
        $this->rate('2026-11-03', '12.2500');

        DB::enableQueryLog();
        $tpm = (new MarketBoard)->current()['tpm'];
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(12.25, $tpm->value, 0.00001);
        $this->assertEqualsWithDelta(-0.25, $tpm->change, 0.00001);
        $this->assertEqualsWithDelta(-2.0, $tpm->changePercent, 0.00001);
        // The date is the current value's, not the decision's.
        $this->assertSame('2026-11-03', $tpm->validFrom->toDateString());
        $this->assertCount(1, $queries, 'The last decision rides in the same query.');
    }

    public function test_a_policy_rate_that_never_changed_shows_no_move(): void
    {
        $this->rate('2026-11-02', '12.2500');
        $this->rate('2026-11-03', '12.2500');

        $this->assertSame(0.0, (new MarketBoard)->current()['tpm']->change);

        MarketRate::query()->where('valid_from', '2026-11-02')->delete();

        $only = (new MarketBoard)->current()['tpm'];
        $this->assertSame(0.0, $only->change);
        $this->assertSame(0.0, $only->changePercent);
    }

    public function test_a_future_rate_is_not_the_last_decision(): void
    {
        $this->rate('2026-11-02', '12.5000');
        $this->rate('2026-11-03', '12.2500');
        $this->rate('2026-11-04', '11.0000');

        $this->assertEqualsWithDelta(-0.25, (new MarketBoard)->current()['tpm']->change, 0.00001);
    }

    public function test_other_series_still_move_against_their_previous_value(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3100.0000', 'valid_from' => '2026-11-01']);
        MarketRate::factory()->trm()->create(['value' => '3200.0000', 'valid_from' => '2026-11-02']);
        MarketRate::factory()->trm()->create(['value' => '3200.0000', 'valid_from' => '2026-11-03']);

        $this->assertSame(0.0, (new MarketBoard)->current()['trm']->change);
    }
}
