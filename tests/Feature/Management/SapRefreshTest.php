<?php

namespace Tests\Feature\Management;

use App\Auth\MicrosoftLogin;
use App\Http\Controllers\Management\SapRefreshController;
use App\Management\SapRefresh;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Management\Concerns\FakesBasesSap;
use Tests\TestCase;

/**
 * «Actualizar ventas y cartera» of the «Panel gerencial»
 * (odd/tasks/panel-gerencial-sap.md, T3; the Bases SAP guide §2): the
 * Portal asks the Bases SAP API, on the server and with its own key, for
 * fresh VENTAS and CARTERA; the home follows both requests through a
 * shared 30-second cache, re-reads the views when both end, and says when
 * the data were last updated. Every call is faked: no real network.
 */
class SapRefreshTest extends TestCase
{
    use FakesBasesSap;
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const API = 'https://basessap.test';

    private const KEY = 'portal-test-key-123';

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'America/Bogota'));
        $this->company = Company::factory()->withManagementGroup(self::GROUP)->create(['slug' => 'segurex']);
        $app = App::factory()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($app, $this->company);
        $this->manager = $this->manager();
        $this->fakeBasesSap();
        $this->sapSale(['total' => 1000, 'ganancia_bruta' => 250]);
        config(['services.bases_sap' => ['url' => self::API, 'key' => self::KEY]]);
    }

    private function manager(?Company $company = null, string $group = self::GROUP): User
    {
        $user = User::factory()->microsoft($company ?? $this->company)->create(['name' => 'Ana Pérez', 'email' => 'ana.perez@segurex.com.co']);
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $user->syncGroups([['id' => $group, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user;
    }

    private function press(?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->manager)->fresh())->post(route('management.sap-refresh'));
    }

    private function panel(?User $as = null): string
    {
        $html = $this->actingAs(($as ?? $this->manager)->fresh())->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~<section id="panel-gerencial".*?</section>~s', $html, $match), 'The panel is on the page.');

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function request(int $id, string $dataset, string $state = 'pendiente', array $attributes = []): array
    {
        return ['id' => $id, 'estado' => $state, 'correr_desde' => null, 'empresa' => 'SGX', 'conjunto' => $dataset, 'parte' => null, ...$attributes];
    }

    /**
     * The states the faked API gives for VENTAS (request 41) and CARTERA
     * (request 42); a test changes them to move the requests along.
     *
     * @var array<string, array{string, array<string, mixed>}>
     */
    private array $states = [];

    /**
     * Both requests accepted (VENTAS is 41 and new, CARTERA 42 and joined),
     * their states as given, and no last update known.
     *
     * @param  array<string, mixed>  $ventasAttributes
     * @param  array<string, mixed>  $carteraAttributes
     */
    private function fakeApi(string $ventas = 'pendiente', string $cartera = 'pendiente', array $ventasAttributes = [], array $carteraAttributes = []): void
    {
        $faked = $this->states !== [];
        $this->states = ['VENTAS' => [$ventas, $ventasAttributes], 'CARTERA' => [$cartera, $carteraAttributes]];

        if ($faked) {
            return;
        }

        Http::fake([
            self::API.'/api/v1/solicitudes' => fn (Request $request) => $request['conjunto'] === 'VENTAS'
                ? Http::response(self::request(41, 'VENTAS'), 201)
                : Http::response(self::request(42, 'CARTERA'), 200),
            self::API.'/api/v1/solicitudes/41' => fn () => Http::response(self::request(41, 'VENTAS', ...$this->states['VENTAS'])),
            self::API.'/api/v1/solicitudes/42' => fn () => Http::response(self::request(42, 'CARTERA', ...$this->states['CARTERA'])),
            self::API.'/api/v1/estado*' => Http::response(['message' => 'Error'], 500),
        ]);
    }

    /** @return list<Request> */
    private function sent(string $path): array
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === $path)
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    // --- The button ------------------------------------------------------------

    public function test_the_button_asks_for_sales_and_receivables_with_the_portals_key(): void
    {
        $this->fakeApi();

        $this->press()->assertRedirect(route('home').'#panel-gerencial')
            ->assertSessionHas('management_status', 'Pedimos la actualización de ventas y cartera. Suele tardar unos minutos.');

        $posts = $this->sent('/api/v1/solicitudes');
        $this->assertCount(2, $posts);

        foreach (['VENTAS', 'CARTERA'] as $index => $dataset) {
            $post = $posts[$index];
            $this->assertSame('POST', $post->method());
            $this->assertSame(['Bearer '.self::KEY], $post->header('Authorization'));
            $this->assertSame(['application/json'], $post->header('Accept'));
            $this->assertSame([
                'empresa' => 'SGX',
                'conjunto' => $dataset,
                'parte' => null,
                'solicitado_por' => ['correo' => 'ana.perez@segurex.com.co', 'nombre' => 'Ana Pérez'],
            ], $post->data());
        }
    }

    public function test_the_panel_offers_the_button_as_a_plain_form(): void
    {
        $this->fakeApi();

        $html = $this->actingAs($this->manager->fresh())->get('/')->getContent();

        $this->assertMatchesRegularExpression('~<form method="POST" action="'.preg_quote(route('management.sap-refresh'), '~').'"~', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('Actualizar ventas y cartera', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
        $this->assertStringNotContainsString('basessap.test', $html);
    }

    public function test_without_the_api_configured_there_is_no_button(): void
    {
        config(['services.bases_sap.key' => null]);

        $this->assertStringNotContainsString('Actualizar ventas y cartera', $this->panel());
        $this->press()->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_only_the_companys_management_may_ask(): void
    {
        $ordinary = User::factory()->microsoft($this->company)->create();
        $this->press($ordinary)->assertForbidden();

        $admin = User::factory()->microsoft($this->company)->create();
        $admin->assignRole('portal_admin');
        $this->press($admin)->assertForbidden();

        $otherGroup = $this->manager(group: 'bbbbbbbb-0000-4000-8000-000000000002');
        $this->press($otherGroup)->assertForbidden();

        auth()->logout();
        $this->post(route('management.sap-refresh'))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    public function test_a_company_without_a_sap_code_cannot_ask(): void
    {
        $this->company->forceFill(['slug' => 'otra'])->save();

        $this->press()->assertNotFound();
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function refusals(): array
    {
        return [
            'revoked key' => [401, 'No pudimos pedir la actualización. Avísale a TI.'],
            'invalid body' => [422, 'No pudimos pedir la actualización. Avísale a TI.'],
            'too many requests' => [429, 'Hay muchas solicitudes en este momento. Inténtalo de nuevo en un minuto.'],
            'server error' => [503, 'No pudimos pedir la actualización. Inténtalo de nuevo; las cifras que ves siguen siendo válidas.'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_gives_a_plain_message_and_the_button_stays(int $status, string $message): void
    {
        Http::fake([
            self::API.'/api/v1/solicitudes' => Http::response(['message' => 'Llave inválida.', 'errors' => ['empresa' => 'detalle técnico']], $status, ['Retry-After' => '30']),
            self::API.'/api/v1/estado*' => Http::response([], 500),
        ]);

        $this->press()->assertRedirect(route('home').'#panel-gerencial')->assertSessionHas('management_status', $message);
        // The first refusal stops: the second request is not tried.
        $this->assertCount(1, $this->sent('/api/v1/solicitudes'));

        $panel = $this->panel();
        $this->assertStringContainsString($message, $panel);
        $this->assertStringContainsString('Actualizar ventas y cartera', $panel);
        $this->assertStringNotContainsString('Llave inválida', $panel);
        $this->assertStringNotContainsString('detalle técnico', $panel);
        $this->assertStringContainsString('$1.000', $panel, 'The figures stay.');
    }

    public function test_a_network_failure_gives_a_plain_message(): void
    {
        Http::fake([
            self::API.'/api/v1/solicitudes' => Http::failedConnection('cURL error 28: Operation timed out'),
            self::API.'/api/v1/estado*' => Http::response([], 500),
        ]);

        $this->press()->assertSessionHas('management_status', 'No pudimos pedir la actualización. Inténtalo de nuevo; las cifras que ves siguen siendo válidas.');
        $this->assertStringNotContainsString('cURL', $this->panel());
    }

    public function test_the_button_has_its_own_rate_limit(): void
    {
        $this->fakeApi();

        foreach (range(1, SapRefreshController::PER_MINUTE) as $press) {
            $this->press()->assertRedirect();
        }

        $this->press()->assertTooManyRequests();
    }

    // --- Following the requests ---------------------------------------------------

    /**
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function states(): array
    {
        return [
            'both pending' => ['pendiente', 'pendiente', [], 'Pedimos los datos a SAP. Suele tardar unos minutos.'],
            'queued until a time' => ['en_cola', 'pendiente', ['correr_desde' => '2026-11-17T12:45:00-05:00'], 'Se actualizará a las 12:45.'],
            'queued behind another' => ['en_cola', 'en_cola', [], 'Hay otra actualización en curso; esta sigue después.'],
            'taken' => ['tomada', 'pendiente', [], 'Actualizando datos…'],
            'one failed' => ['fallida', 'terminada', ['error' => 'HANA: timeout'], 'No se pudo actualizar. Inténtalo de nuevo más tarde.'],
            'one expired' => ['terminada', 'vencida', [], 'La actualización tardó demasiado. Inténtalo de nuevo.'],
            'both done' => ['terminada', 'terminada', [], 'Datos actualizados.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $ventasAttributes
     */
    #[DataProvider('states')]
    public function test_the_panel_says_how_the_update_goes(string $ventas, string $cartera, array $ventasAttributes, string $message): void
    {
        $this->fakeApi($ventas, $cartera, $ventasAttributes);
        $this->press();

        $panel = $this->panel();

        $this->assertStringContainsString($message, $panel);
        $this->assertStringNotContainsString('HANA', $panel);
    }

    public function test_the_status_is_asked_once_per_half_minute_for_every_viewer(): void
    {
        $this->fakeApi('tomada', 'pendiente');
        $this->press();

        $this->panel();
        $this->panel($this->manager());
        $this->assertCount(1, $this->sent('/api/v1/solicitudes/41'));
        $this->assertCount(1, $this->sent('/api/v1/solicitudes/42'));
        $this->assertSame(['Bearer '.self::KEY], $this->sent('/api/v1/solicitudes/41')[0]->header('Authorization'));

        $this->travel(SapRefresh::STATUS_CACHE_SECONDS + 1)->seconds();
        $this->panel();
        $this->assertCount(2, $this->sent('/api/v1/solicitudes/41'));
    }

    public function test_when_both_end_the_views_are_read_again_once(): void
    {
        $this->fakeApi('tomada', 'tomada');
        $this->press();
        $this->assertStringContainsString('$1.000', $this->panel());

        // The robot loads new rows; the cached figures stay until both requests end.
        $this->sapSale(['total' => 500]);
        $this->assertStringContainsString('$1.000', $this->panel());

        $this->travel(SapRefresh::STATUS_CACHE_SECONDS + 1)->seconds();
        $this->fakeApi('terminada', 'terminada');
        $panel = $this->panel();
        $this->assertStringContainsString('$1.500', $panel);
        $this->assertStringContainsString('Datos actualizados.', $panel);

        // Finished: no more status calls, and the figures are cached again.
        $this->travel(SapRefresh::STATUS_CACHE_SECONDS + 1)->seconds();
        $this->sapSale(['total' => 1]);
        $this->assertStringContainsString('$1.500', $this->panel());
        $this->assertCount(2, $this->sent('/api/v1/solicitudes/41'));
    }

    public function test_an_unexpected_client_failure_is_reported_and_the_page_works(): void
    {
        Exceptions::fake();
        // No fake for these calls: the client throws on a stray request.
        Http::fake([self::API.'/api/v1/solicitudes' => fn (Request $request) => Http::response(self::request($request['conjunto'] === 'VENTAS' ? 41 : 42, $request['conjunto']), 201)]);
        $this->press();

        $panel = $this->panel();

        $this->assertStringContainsString('Actualizar ventas y cartera', $panel);
        $this->assertStringContainsString('$1.000', $panel);
        $this->assertStringNotContainsString('Datos actualizados', $panel);
        Exceptions::assertReported(StrayRequestException::class);
    }

    public function test_an_unknown_status_shows_no_message(): void
    {
        Http::fake([
            self::API.'/api/v1/solicitudes' => fn (Request $request) => Http::response(self::request($request['conjunto'] === 'VENTAS' ? 41 : 42, $request['conjunto']), 201),
            self::API.'/api/v1/solicitudes/*' => Http::response(['message' => 'Llave inválida.'], 401),
            self::API.'/api/v1/estado*' => Http::response([], 500),
        ]);
        $this->press();

        $panel = $this->panel();

        $this->assertStringNotContainsString('Llave', $panel);
        $this->assertStringNotContainsString('Pedimos los datos', $panel);
        $this->assertStringContainsString('Actualizar ventas y cartera', $panel);
    }

    // --- Datos actualizados a las … ----------------------------------------------

    public function test_the_older_of_the_two_last_updates_is_shown(): void
    {
        Http::fake([
            self::API.'/api/v1/estado?empresa=SGX&conjunto=VENTAS' => Http::response(['empresa' => 'SGX', 'conjunto' => 'VENTAS', 'partes' => ['ventas' => ['actualizado_en' => '2026-11-17T10:42:00-05:00']]]),
            self::API.'/api/v1/estado?empresa=SGX&conjunto=CARTERA' => Http::response(['empresa' => 'SGX', 'conjunto' => 'CARTERA', 'partes' => ['cartera' => ['actualizado_en' => '2026-11-17T14:15:00+00:00']]]),
        ]);

        $this->assertStringContainsString('Datos actualizados a las 09:15', $this->panel());
        $this->panel();

        $this->assertCount(2, $this->sent('/api/v1/estado'), 'Cached: one call per set.');
        foreach ($this->sent('/api/v1/estado') as $request) {
            $this->assertSame(['Bearer '.self::KEY], $request->header('Authorization'));
        }
    }

    public function test_an_older_day_is_named(): void
    {
        Http::fake([
            self::API.'/api/v1/estado*' => Http::response(['partes' => ['ventas' => ['actualizado_en' => '2026-11-16T02:05:00-05:00']]]),
        ]);

        $this->assertStringContainsString('Datos actualizados el 16 de noviembre a las 02:05', $this->panel());
    }

    /**
     * @return array<string, array{array<string, mixed>|string, int}>
     */
    public static function unknownUpdates(): array
    {
        return [
            'never applied' => [['partes' => ['ventas' => ['actualizado_en' => null]]], 200],
            'no parts' => [['partes' => []], 200],
            'server error' => [[], 500],
            'not json' => ['<html>', 200],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    #[DataProvider('unknownUpdates')]
    public function test_without_a_known_update_the_line_is_left_out(array|string $body, int $status): void
    {
        Http::fake([self::API.'/api/v1/estado*' => Http::response($body, $status)]);

        $panel = $this->panel();

        $this->assertStringNotContainsString('Datos actualizados', $panel);
        $this->assertStringContainsString('$1.000', $panel);
    }
}
