<?php

namespace Tests\Feature\Markets;

use App\Markets\MarketBoard;
use App\Models\MarketRate;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Tests\Feature\Markets\Concerns\FakesMarketSources;
use Tests\TestCase;

/**
 * `php artisan portal:sync-markets`: the TRM from datos.gov.co and the
 * euro (TRM × the ECB's dollars per euro), by hand and twice a day. The
 * Banco de la República answers its samples and FRED has no key here
 * (SyncMarketSourcesTest covers both).
 */
class SyncMarketsCommandTest extends TestCase
{
    use FakesMarketSources;
    use RefreshDatabase;

    private const TRM_URL = 'https://www.datos.gov.co/resource/32sa-8pi3.json';

    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        // The app and PHP run in UTC; Bogotá is only what the test clock says.
        $this->phpTimezone = date_default_timezone_get();
        config(['app.timezone' => 'UTC', 'services.fred.key' => null]);
        date_default_timezone_set('UTC');

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'America/Bogota'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);

        parent::tearDown();
    }

    /** @return list<array<string, string>> */
    private function trmRows(): array
    {
        return [
            ['valor' => '3182.99', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-31T00:00:00.000', 'vigenciahasta' => '2026-11-03T00:00:00.000'],
            ['valor' => '3190.50', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-30T00:00:00.000', 'vigenciahasta' => '2026-10-30T00:00:00.000'],
            ['valor' => '3201.10', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-29T00:00:00.000', 'vigenciahasta' => '2026-10-29T00:00:00.000'],
        ];
    }

    private function ecbXml(string $rate = '1.0800', string $date = '2026-11-03'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
    <gesmes:subject>Reference rates</gesmes:subject>
    <gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender>
    <Cube><Cube time='{$date}'><Cube currency='USD' rate='{$rate}'/><Cube currency='JPY' rate='165.20'/></Cube></Cube>
</gesmes:Envelope>
XML;
    }

    /** Fakes every host; pass a response (or closure) to change the TRM or the ECB. */
    private function fakeSources(mixed $trm = null, mixed $ecb = null): void
    {
        Http::fake([
            'www.datos.gov.co/*' => $trm ?? Http::response($this->trmRows()),
            'www.ecb.europa.eu/*' => $ecb ?? Http::response($this->ecbXml(), 200, ['Content-Type' => 'text/xml']),
            'totoro.banrep.gov.co/*' => $this->banrepResponder(),
        ]);
    }

    /** The rows of the TRM and the euro only. */
    private function rates(): int
    {
        return MarketRate::query()->whereIn('code', ['trm', 'eur'])->count();
    }

    public function test_it_stores_the_trm_and_the_euro(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets')->assertSuccessful();

        $this->assertSame(3, MarketRate::query()->where('code', 'trm')->count());

        $trm = MarketRate::query()->where('code', 'trm')->where('valid_from', '2026-10-31')->firstOrFail();
        $this->assertSame('3182.9900', $trm->value);
        $this->assertSame('2026-11-03', $trm->valid_until->toDateString());
        $this->assertSame('datos.gov.co', $trm->source);
        $this->assertSame('2026-11-03 15:00:00', $trm->fetched_at->utc()->toDateTimeString());

        // 3182.99 × 1.08, on the date the ECB gives.
        $eur = MarketRate::query()->where('code', 'eur')->sole();
        $this->assertSame('3437.6292', $eur->value);
        $this->assertSame('2026-11-03', $eur->valid_from->toDateString());
        $this->assertNull($eur->valid_until);
        $this->assertSame('ecb.europa.eu', $eur->source);
    }

    public function test_the_euro_of_a_date_uses_the_trm_in_force_on_that_date(): void
    {
        $rows = $this->trmRows();
        array_unshift($rows, ['valor' => '9999.00', 'unidad' => 'COP', 'vigenciadesde' => '2026-11-04T00:00:00.000', 'vigenciahasta' => '2026-11-04T00:00:00.000']);
        $this->fakeSources(trm: Http::response($rows));

        $this->artisan('portal:sync-markets')->assertSuccessful();

        // The ECB date is 3 Nov: the TRM published for 4 Nov does not count.
        $this->assertSame('3437.6292', MarketRate::query()->where('code', 'eur')->sole()->value);
    }

    public function test_an_older_ecb_date_takes_the_trm_of_that_older_date(): void
    {
        $this->fakeSources(ecb: Http::response($this->ecbXml('1.0800', '2026-10-30')));

        $this->artisan('portal:sync-markets')->assertSuccessful();

        // 3190.50 was in force on 30 Oct (3182.99 started on the 31st).
        $eur = MarketRate::query()->where('code', 'eur')->sole();
        $this->assertSame('2026-10-30', $eur->valid_from->toDateString());
        $this->assertSame('3445.7400', $eur->value);
    }

    public function test_the_morning_run_before_the_ecb_publishes_does_not_rewrite_the_previous_euro(): void
    {
        // The TRM of 4 Nov is already published ahead on the 3rd.
        $rows = [
            ['valor' => '3200.00', 'unidad' => 'COP', 'vigenciadesde' => '2026-11-04T00:00:00.000', 'vigenciahasta' => '2026-11-04T00:00:00.000'],
            ['valor' => '3182.99', 'unidad' => 'COP', 'vigenciadesde' => '2026-10-31T00:00:00.000', 'vigenciahasta' => '2026-11-03T00:00:00.000'],
        ];
        $ecb = ['date' => '2026-11-03', 'rate' => '1.0800'];
        $this->fakeSources(
            trm: function () use (&$rows) {
                return Http::response($rows);
            },
            ecb: function () use (&$ecb) {
                return Http::response($this->ecbXml($ecb['rate'], $ecb['date']));
            },
        );

        // 13:10 on the 3rd: the ECB has published the 3rd.
        Carbon::setTestNow(Carbon::parse('2026-11-03 13:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets')->assertSuccessful();
        $this->assertSame('3437.6292', $this->euro('2026-11-03')->value);

        // 07:10 on the 4th: the ECB (published at about 16:00 CET) still serves the 3rd.
        Carbon::setTestNow(Carbon::parse('2026-11-04 07:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets')->assertSuccessful();
        $this->assertSame('3437.6292', $this->euro('2026-11-03')->value, 'The 3rd must stay TRM(3rd) x ECB(3rd).');
        $this->assertSame(1, MarketRate::query()->where('code', 'eur')->count());

        // 13:10 on the 4th: the ECB has published the 4th.
        $ecb = ['date' => '2026-11-04', 'rate' => '1.1000'];
        Carbon::setTestNow(Carbon::parse('2026-11-04 13:10:00', 'America/Bogota'));
        $this->artisan('portal:sync-markets')->assertSuccessful();

        $this->assertSame('3437.6292', $this->euro('2026-11-03')->value);
        $this->assertSame('3520.0000', $this->euro('2026-11-04')->value);

        $euro = (new MarketBoard)->current()['eur'];
        $this->assertSame('2026-11-04', $euro->validFrom->toDateString());
        $this->assertEqualsWithDelta(82.3708, $euro->change, 0.00001);
        $this->assertEqualsWithDelta(2.4, $euro->changePercent, 0.00001);
    }

    public function test_a_weekend_run_keeps_rewriting_the_same_friday_row_with_the_same_value(): void
    {
        $this->fakeSources(
            trm: function () {
                return Http::response($this->trmRows());
            },
            ecb: function () {
                return Http::response($this->ecbXml('1.0800', '2026-10-30'));
            },
        );

        foreach (['2026-10-31 07:10:00', '2026-10-31 13:10:00', '2026-11-01 07:10:00', '2026-11-01 13:10:00'] as $at) {
            Carbon::setTestNow(Carbon::parse($at, 'America/Bogota'));
            $this->artisan('portal:sync-markets')->assertSuccessful();
        }

        $this->assertSame(1, MarketRate::query()->where('code', 'eur')->count());
        $this->assertSame('3445.7400', $this->euro('2026-10-30')->value);
    }

    private function euro(string $date): MarketRate
    {
        return MarketRate::query()->where('code', 'eur')->where('valid_from', $date)->sole();
    }

    public function test_it_asks_the_official_hosts_for_the_latest_ten_without_redirects(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets')->assertSuccessful();

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request) {
            if (! str_starts_with($request->url(), self::TRM_URL.'?')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                // The newest revision of a validity comes first.
                && $query['$order'] === 'vigenciadesde DESC, :updated_at DESC'
                && $query['$limit'] === '10';
        });
        Http::assertSent(fn (Request $request) => $request->url() === self::ECB_URL);
    }

    public function test_running_it_again_updates_rows_instead_of_duplicating_them(): void
    {
        // Fresh responses on every call: a read body is not read twice.
        $rows = $this->trmRows();
        $this->fakeSources(
            trm: function () use (&$rows) {
                return Http::response($rows);
            },
            ecb: function () {
                return Http::response($this->ecbXml());
            },
        );

        // The same data twice: the same four rows with the same values.
        $this->artisan('portal:sync-markets')->assertSuccessful();
        $this->artisan('portal:sync-markets')->assertSuccessful();

        $this->assertSame(4, $this->rates());
        $this->assertSame('3182.9900', MarketRate::query()->where('code', 'trm')->where('valid_from', '2026-10-31')->sole()->value);
        $this->assertSame('3437.6292', $this->euro('2026-11-03')->value);

        // The dataset corrects a TRM: its row and the euro of the same date follow.
        $rows[0]['valor'] = '3200.00';
        $this->artisan('portal:sync-markets')->assertSuccessful();

        $this->assertSame(4, $this->rates());
        $this->assertSame('3200.0000', MarketRate::query()->where('code', 'trm')->where('valid_from', '2026-10-31')->sole()->value);
        $this->assertSame('3456.0000', $this->euro('2026-11-03')->value);
    }

    public function test_it_prints_what_it_saved(): void
    {
        $this->fakeSources();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('TRM: 3 valores guardados.')
            ->expectsOutputToContain('Euro: 1 valor guardado.')
            ->assertSuccessful();
    }

    public function test_a_failed_trm_keeps_the_last_value_and_the_euro_follows_it(): void
    {
        MarketRate::factory()->trm()->create(['value' => '3000', 'valid_from' => '2026-10-31', 'valid_until' => '2026-11-03']);
        $this->fakeSources(trm: Http::response('Server error SECRET-BODY', 500));
        Log::spy();

        $this->artisan('portal:sync-markets')
            ->expectsOutputToContain('TRM: no se pudo actualizar')
            ->assertFailed();

        $this->assertSame('3000.0000', MarketRate::query()->where('code', 'trm')->sole()->value);
        $this->assertSame('3240.0000', MarketRate::query()->where('code', 'eur')->sole()->value);
        $this->assertWarned('trm', 'http_500');
    }

    public function test_a_failed_ecb_keeps_the_euro_and_the_trm_is_still_stored(): void
    {
        MarketRate::factory()->eur()->create(['value' => '3300', 'valid_from' => '2026-10-30']);
        $this->fakeSources(ecb: Http::response('<html>down SECRET-BODY</html>', 503));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(3, MarketRate::query()->where('code', 'trm')->count());
        $this->assertSame('3300.0000', MarketRate::query()->where('code', 'eur')->sole()->value);
        $this->assertWarned('eur', 'http_503');
    }

    public function test_without_any_trm_the_euro_is_not_computed(): void
    {
        $this->fakeSources(trm: Http::response('nope', 500));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(0, $this->rates());
        $this->assertWarned('eur', 'no_trm');
    }

    public function test_a_redirect_is_a_failure_and_is_not_followed(): void
    {
        $this->fakeSources(
            trm: Http::response('', 302, ['Location' => 'https://evil.example/trm.json']),
            ecb: Http::response('', 301, ['Location' => 'https://evil.example/ecb.xml']),
        );
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        Http::assertSentCount(4);
        $this->assertSame(0, $this->rates());
        $this->assertWarned('trm', 'http_302');
        $this->assertWarned('eur', 'http_301');
    }

    public function test_a_response_over_the_size_limit_is_rejected(): void
    {
        config(['markets.max_bytes' => 1024 * 1024]);
        $this->fakeSources(trm: Http::response(str_repeat(' ', 1024 * 1024).'[]', 200));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(0, MarketRate::query()->where('code', 'trm')->count());
        $this->assertWarned('trm', 'too_large');
    }

    public function test_a_response_exactly_at_the_size_limit_is_read(): void
    {
        config(['markets.max_bytes' => 1024 * 1024]);
        $json = json_encode($this->trmRows());
        $this->fakeSources(trm: Http::response($json.str_repeat(' ', 1024 * 1024 - strlen($json)), 200));

        $this->artisan('portal:sync-markets')->assertSuccessful();

        $this->assertSame(3, MarketRate::query()->where('code', 'trm')->count());
    }

    public function test_a_source_that_trickles_bytes_is_cut_at_the_deadline(): void
    {
        config(['markets.timeout' => 1]);

        // Guzzle's stream handler keeps only an idle timeout between reads, so a
        // source sending a byte now and then would never end without our deadline.
        $stalling = new class(Utils::streamFor('')) implements StreamInterface
        {
            use StreamDecoratorTrait;

            public function read(int $length): string
            {
                usleep(250_000);

                return 'a';
            }

            public function eof(): bool
            {
                return false;
            }
        };
        $this->fakeSources(trm: fn () => Create::promiseFor(new Psr7Response(200, [], $stalling)));
        Log::spy();

        $started = microtime(true);
        $this->artisan('portal:sync-markets')->assertFailed();
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        $this->assertLessThan(4.0, $elapsed);
        $this->assertSame(0, MarketRate::query()->where('code', 'trm')->count());
        $this->assertWarned('trm', 'timeout');
    }

    public function test_the_body_is_closed_when_the_answer_is_not_a_200(): void
    {
        $body = Utils::streamFor('Server error');
        $this->fakeSources(trm: fn () => Create::promiseFor(new Psr7Response(500, [], $body)));

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertFalse($body->isReadable(), 'The body of a failed answer must be closed.');
    }

    public function test_the_body_is_closed_when_the_declared_size_is_over_the_limit(): void
    {
        $body = Utils::streamFor('[]');
        $this->fakeSources(trm: fn () => Create::promiseFor(new Psr7Response(200, ['Content-Length' => (string) (2 * 1024 * 1024)], $body)));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertFalse($body->isReadable(), 'The body of a too large answer must be closed.');
        $this->assertWarned('trm', 'too_large');
    }

    public function test_a_connection_error_is_a_failure_without_its_message_in_the_log(): void
    {
        $this->fakeSources(
            trm: fn () => throw new ConnectionException('cURL error 28: timed out for https://www.datos.gov.co/?token=SECRET-TOKEN'),
        );
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertWarned('trm', 'connection');
        $this->assertSame(0, $this->rates());
    }

    public function test_an_xml_with_a_doctype_is_rejected_and_the_trm_is_still_stored(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]>'.substr($this->ecbXml(), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
        $this->fakeSources(ecb: Http::response($xml, 200));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertSame(3, MarketRate::query()->where('code', 'trm')->count());
        $this->assertSame(0, MarketRate::query()->where('code', 'eur')->count());
        $this->assertWarned('eur', 'doctype');
    }

    public function test_a_malformed_trm_body_is_a_failure(): void
    {
        $this->fakeSources(trm: Http::response('<html>SECRET-BODY</html>', 200));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        $this->assertWarned('trm', 'invalid_json');
    }

    public function test_the_log_has_a_short_code_and_never_the_body(): void
    {
        $this->fakeSources(trm: Http::response('SECRET-BODY', 500), ecb: Http::response('SECRET-BODY', 200));
        Log::spy();

        $this->artisan('portal:sync-markets')->assertFailed();

        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (string $message, array $context = []) {
            $this->assertStringNotContainsString('SECRET', $message.json_encode($context));
            $this->assertLessThanOrEqual(32, strlen((string) ($context['code'] ?? '')));

            return true;
        });
    }

    public function test_it_is_scheduled_twice_a_day_in_bogota_time(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:sync-markets'));

        $this->assertNotNull($event, 'portal:sync-markets is not scheduled.');
        $this->assertSame('10 7,13 * * *', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        // The lock of a killed run expires after 10 minutes, not after 24 hours.
        $this->assertSame(10, $event->expiresAt);
    }

    private function assertWarned(string $source, string $code): void
    {
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []) => ($context['source'] ?? null) === $source && ($context['code'] ?? null) === $code
        )->once();
    }
}
