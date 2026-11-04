<?php

namespace Tests\Feature\Markets;

use App\Markets\MarketBoard;
use App\Models\MarketRate;
use App\View\Home\MarketFigure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Markets\Concerns\FakesMarketSources;
use Tests\TestCase;

/**
 * `portal:sync-markets` with the sources of odd/tasks/mercados-o5.md M2:
 * the policy rate and COLCAP from the Banco de la República (SDMX, no key)
 * and Brent and the input prices from FRED (with FRED_API_KEY). Every
 * source stands alone and keeps its last value when it fails.
 */
class SyncMarketSourcesTest extends TestCase
{
    use FakesMarketSources;
    use RefreshDatabase;

    private const KEY = 'abcdef0123456789abcdef0123456789';

    private const TPM_URL = 'https://totoro.banrep.gov.co/nsi-jax-ws/rest/data/ESTAT,DF_CBR_DAILY_LATEST,1.0/all/ALL/';

    private const COLCAP_URL = 'https://totoro.banrep.gov.co/nsi-jax-ws/rest/data/ESTAT,DF_COLCAP_MONTHLY_LATEST,1.0/all/ALL/';

    private const FRED_URL = 'https://api.stlouisfed.org/fred/series/observations';

    private const FRED_SERIES = ['DCOILBRENTEU', 'PZINCUSDM', 'PCOPPUSDM', 'PALUMUSDM', 'WPU102502', 'WPU1017', 'WPU06130302', 'WPU0662', 'PCU326160326160'];

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpTimezone = date_default_timezone_get();
        config(['app.timezone' => 'UTC', 'services.fred.key' => null]);
        date_default_timezone_set('UTC');

        Carbon::setTestNow(Carbon::parse('2026-11-04 07:10:00', 'America/Bogota'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);

        parent::tearDown();
    }

    /**
     * Fakes every host; the TRM and the ECB answer nothing usable unless
     * asked, they are covered by SyncMarketsCommandTest.
     *
     * @param  array<string, mixed>  $fred
     */
    private function fakeSources(mixed $tpm = null, mixed $colcap = null, array $fred = []): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([['valor' => '3182.99', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-31T00:00:00.000']]),
            'www.ecb.europa.eu/*' => Http::response('<x/>', 503),
            'totoro.banrep.gov.co/*' => $this->banrepResponder($tpm, $colcap),
            'api.stlouisfed.org/*' => $this->fredResponder($fred),
        ]);
    }

    private function value(string $code, string $date): ?string
    {
        return MarketRate::query()->where('code', $code)->where('valid_from', $date)->value('value');
    }

    // --- Banco de la República ------------------------------------------------

    public function test_it_stores_the_policy_rate_and_colcap_from_the_banco_de_la_republica(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('Tasa de política (BanRep): 1 valor guardado.')
            ->expectsOutputToContain('COLCAP: 1 valor guardado.');

        // The live `_LATEST` answers carry only today's value.
        $this->assertSame(['2026-11-04' => '12.2500'], $this->history('tpm'));
        $this->assertSame(['2026-10-01' => '2536.5100'], $this->history('colcap'));
        $this->assertSame(['banrep.gov.co'], MarketRate::query()->whereIn('code', ['tpm', 'colcap'])->distinct()->pluck('source')->all());
        $this->assertNull(MarketRate::query()->where('code', 'tpm')->value('valid_until'));
    }

    public function test_it_asks_the_fixed_banrep_urls_with_its_own_user_agent(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets');

        foreach ([self::TPM_URL, self::COLCAP_URL] as $url) {
            Http::assertSent(fn (Request $request) => $request->url() === $url
                && $request->method() === 'GET'
                && $request->header('User-Agent') === ['PortalRYR/1.0']);
        }
    }

    public function test_a_banrep_answer_with_a_doctype_is_refused_and_the_last_value_stays(): void
    {
        // Fetched yesterday: a monthly series already read today would not be asked again.
        MarketRate::factory()->create(['code' => 'colcap', 'value' => '1400', 'valid_from' => '2026-09-01', 'source' => 'banrep.gov.co', 'fetched_at' => now()->subDay()]);
        $this->fakeSources(colcap: Http::response($this->marketFixture('banrep-doctype.xml'), 200));
        Log::spy();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('COLCAP: no se pudo actualizar (doctype)')
            ->assertFailed();

        $this->assertSame(['1400.0000'], MarketRate::query()->where('code', 'colcap')->pluck('value')->all());
        // The other source of the same host still came in.
        $this->assertSame('12.2500', $this->value('tpm', '2026-11-04'));
        $this->assertWarned('colcap', 'doctype');
    }

    public function test_banrep_under_maintenance_keeps_the_last_value(): void
    {
        MarketRate::factory()->create(['code' => 'tpm', 'value' => '9.25', 'valid_from' => '2026-11-02', 'source' => 'banrep.gov.co']);
        $this->fakeSources(tpm: Http::response('<html>Mantenimiento</html>', 503));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(['9.2500'], MarketRate::query()->where('code', 'tpm')->pluck('value')->all());
        $this->assertSame('2536.5100', $this->value('colcap', '2026-10-01'));
        $this->assertWarned('tpm', 'http_503');
    }

    public function test_a_banrep_answer_that_is_not_xml_is_a_failure(): void
    {
        $this->fakeSources(tpm: Http::response('{"error":"nope"}', 200));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(0, MarketRate::query()->where('code', 'tpm')->count());
        $this->assertWarned('tpm', 'invalid_xml');
    }

    // --- FRED --------------------------------------------------------------

    public function test_without_a_fred_key_brent_and_the_inputs_are_skipped_with_a_clear_line(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([['valor' => '3182.99', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-31T00:00:00.000']]),
            'www.ecb.europa.eu/*' => Http::response('<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-11-02"><Cube currency="USD" rate="1.08"/></Cube></Cube></gesmes:Envelope>'),
            'totoro.banrep.gov.co/*' => $this->banrepResponder(),
        ]);

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('Sin FRED_API_KEY: se omiten Brent y los insumos.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'stlouisfed'));
        $this->assertSame(0, MarketRate::query()->where('source', 'fred.stlouisfed.org')->count());
    }

    public function test_with_a_key_it_stores_brent_and_every_input_series(): void
    {
        config(['services.fred.key' => self::KEY]);
        $this->fakeSources();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('Petróleo Brent: 3 valores guardados.')
            ->expectsOutputToContain('Zinc (US$/t): 3 valores guardados.')
            ->doesntExpectOutputToContain('FRED_API_KEY');

        // The «.» of 23 Oct is skipped.
        $this->assertSame(['2026-10-22', '2026-10-26', '2026-10-27'], MarketRate::query()->where('code', 'brent')->orderBy('valid_from')->pluck('valid_from')->map->toDateString()->all());
        $this->assertSame('64.5300', $this->value('brent', '2026-10-27'));
        $this->assertSame('2845.5000', $this->value('zinc', '2026-09-01'));

        foreach (['copper', 'aluminum', 'brass', 'steel', 'alkali', 'resins', 'bottles'] as $code) {
            $this->assertSame(3, MarketRate::query()->where('code', $code)->count(), $code);
        }

        $this->assertSame(['fred.stlouisfed.org'], MarketRate::query()->whereNotIn('code', ['trm', 'tpm', 'colcap'])->distinct()->pluck('source')->all());
    }

    public function test_it_asks_fred_for_the_newest_observations_of_each_series(): void
    {
        config(['services.fred.key' => self::KEY]);
        $this->fakeSources();

        $this->artisan('portal:sync-markets');

        foreach (self::FRED_SERIES as $series) {
            Http::assertSent(function (Request $request) use ($series) {
                if (! str_starts_with($request->url(), self::FRED_URL.'?')) {
                    return false;
                }
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return $request->method() === 'GET'
                    && $query === ['series_id' => $series, 'api_key' => self::KEY, 'file_type' => 'json', 'sort_order' => 'desc', 'limit' => '10']
                    && $request->header('User-Agent') === ['PortalRYR/1.0'];
            });
        }
    }

    public function test_one_failing_fred_series_keeps_its_last_value_and_the_others_go_on(): void
    {
        config(['services.fred.key' => self::KEY]);
        MarketRate::factory()->create(['code' => 'copper', 'value' => '9000', 'valid_from' => '2026-08-01', 'source' => 'fred.stlouisfed.org', 'fetched_at' => now()->subDay()]);
        $this->fakeSources(fred: [
            'PCOPPUSDM' => Http::response('Internal Server Error', 500),
            'WPU0662' => Http::response('not json', 200),
        ]);
        Log::spy();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('Cobre (US$/t): no se pudo actualizar (http_500)')
            ->assertFailed();

        $this->assertSame(['9000.0000'], MarketRate::query()->where('code', 'copper')->pluck('value')->all());
        $this->assertSame(0, MarketRate::query()->where('code', 'resins')->count());
        $this->assertSame(3, MarketRate::query()->where('code', 'zinc')->count());
        $this->assertSame(3, MarketRate::query()->where('code', 'brent')->count());
        $this->assertWarned('copper', 'http_500');
        $this->assertWarned('resins', 'invalid_json');
    }

    public function test_the_key_never_reaches_the_log_or_the_output(): void
    {
        config(['services.fred.key' => self::KEY]);
        $this->fakeSources(fred: [
            // FRED echoes what it was asked in its errors.
            'DCOILBRENTEU' => Http::response('{"error_code":400,"error_message":"Bad Request. api_key='.self::KEY.'"}', 400),
            'PZINCUSDM' => fn () => throw new ConnectionException('cURL error 28 for '.self::FRED_URL.'?api_key='.self::KEY),
            'PCOPPUSDM' => fn () => throw new \RuntimeException('Unexpected '.self::KEY),
        ]);
        Log::spy();

        $exit = Artisan::call('portal:sync-markets');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString(self::KEY, $output);
        $this->assertStringContainsString('Petróleo Brent: no se pudo actualizar (http_400)', $output);
        $this->assertWarned('brent', 'http_400');
        $this->assertWarned('zinc', 'connection');
        $this->assertWarned('copper', 'connection');

        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (string $message, array $context = []) {
            $this->assertStringNotContainsString(self::KEY, $message.json_encode($context));

            return true;
        });
    }

    // --- How often -------------------------------------------------------------

    public function test_a_monthly_series_is_read_once_a_day_and_a_daily_one_every_run(): void
    {
        config(['services.fred.key' => self::KEY]);
        $this->fakeSources();

        $this->artisan('portal:sync-markets');
        $morning = Http::recorded()->count();

        Carbon::setTestNow(Carbon::parse('2026-11-04 13:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('Ya consultadas hoy: COLCAP, Zinc (US$/t)');

        $afternoon = Http::recorded()->slice($morning)->map(fn (array $pair) => $pair[0]->url())->values();

        // TRM, ECB, the policy rate and Brent again; COLCAP and the eight monthly FRED series not.
        $this->assertCount(4, $afternoon);
        $this->assertTrue($afternoon->contains(self::TPM_URL));
        $this->assertFalse($afternoon->contains(self::COLCAP_URL));
        $this->assertTrue($afternoon->contains(fn (string $url) => str_contains($url, 'series_id=DCOILBRENTEU')));

        // The next day they are read again.
        Carbon::setTestNow(Carbon::parse('2026-11-05 07:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets');
        $this->assertSame($morning, Http::recorded()->count() - $morning - $afternoon->count());
    }

    public function test_a_monthly_series_that_failed_in_the_morning_is_retried_in_the_afternoon(): void
    {
        config(['services.fred.key' => self::KEY]);
        $down = true;
        $this->fakeSources(colcap: function () use (&$down) {
            return $down ? Http::response('', 503) : Http::response($this->marketFixture('banrep-colcap-live.xml'));
        });
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();
        $this->assertSame(0, MarketRate::query()->where('code', 'colcap')->count());

        $down = false;
        Carbon::setTestNow(Carbon::parse('2026-11-04 13:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets');

        $this->assertSame(1, MarketRate::query()->where('code', 'colcap')->count());
    }

    // --- History: each answer carries one value ----------------------------------

    public function test_every_sync_adds_the_day_of_the_value_and_the_change_comes_from_the_stored_one_before(): void
    {
        MarketRate::factory()->create(['code' => 'colcap', 'value' => '2500', 'valid_from' => '2026-09-01', 'source' => 'banrep.gov.co', 'fetched_at' => now()->subMonth()]);
        $this->fakeSources();

        $this->artisan('portal:sync-markets');

        $this->assertSame(['2026-09-01' => '2500.0000', '2026-10-01' => '2536.5100'], $this->history('colcap'));

        $colcap = MarketFigure::from((new MarketBoard)->current()['colcap']);
        $this->assertSame('2.536,51', $colcap->value);
        $this->assertSame('▲ 1,46 %', $colcap->shortChange());
        $this->assertSame('oct 2026', $colcap->validFromLabel);
    }

    public function test_a_first_sync_shows_the_policy_rate_without_a_move(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets');

        $tpm = MarketFigure::from((new MarketBoard)->current()['tpm']);
        $this->assertSame('12,25 %', $tpm->value);
        $this->assertSame('● 0,00 pp', $tpm->shortChange());
        $this->assertSame('4 nov', $tpm->validFromLabel);
    }

    public function test_the_policy_rate_shows_its_last_decision_and_todays_date(): void
    {
        $answer = ['day' => '20261102', 'value' => '12.5'];
        $this->fakeSources(tpm: function () use (&$answer) {
            return Http::response($this->policyRateXml($answer['day'], $answer['value']));
        });

        foreach ([['20261102', '12.5', '2026-11-02'], ['20261103', '12.5', '2026-11-03'], ['20261104', '12.25', '2026-11-04'], ['20261105', '12.25', '2026-11-05'], ['20261106', '12.25', '2026-11-06']] as [$day, $value, $date]) {
            $answer = ['day' => $day, 'value' => $value];
            Carbon::setTestNow(Carbon::parse("{$date} 07:10:00", 'America/Bogota'));
            $this->artisan('portal:sync-markets');
        }

        $this->assertCount(5, $this->history('tpm'));

        // Two days after the cut, the band still shows it against 12,50 %, with today's date.
        $tpm = MarketFigure::from((new MarketBoard)->current()['tpm']);
        $this->assertSame('12,25 %', $tpm->value);
        $this->assertSame('▼ 0,25 pp', $tpm->shortChange());
        $this->assertSame('Bajó 0,25 puntos porcentuales', $tpm->spokenChange());
        $this->assertSame('crit', $tpm->tone());
        $this->assertSame('2026-11-06', $tpm->validFromIso);
    }

    /**
     * The stored values of a code by date, oldest first.
     *
     * @return array<string, string>
     */
    private function history(string $code): array
    {
        return MarketRate::query()->where('code', $code)->orderBy('valid_from')->get()
            ->mapWithKeys(fn (MarketRate $rate) => [$rate->valid_from->toDateString() => $rate->value])
            ->all();
    }

    private function assertWarned(string $source, string $code): void
    {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['source'] ?? null) === $source && ($context['code'] ?? null) === $code
        )->once();
    }
}
