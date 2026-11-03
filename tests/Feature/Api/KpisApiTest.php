<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\Integration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * PUT /api/v1/kpis (ability `kpis:write`): the SAP robot replaces the whole
 * set of management figures of the one company its token is bound to
 * (odd/tasks/panel-gerencial.md).
 */
class KpisApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private Integration $integration;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00', 'America/Bogota'));

        activity()->withoutLogs(function () {
            $this->segurex = Company::factory()->create(['slug' => 'segurex', 'name' => 'Segurex Latam']);
            $this->derivados = Company::factory()->create(['slug' => 'derivados', 'name' => 'Derivados']);
        });

        [$this->integration, $this->token] = $this->kpiIntegration($this->segurex);
    }

    /**
     * @return array{0: Integration, 1: string}
     */
    private function kpiIntegration(Company $company, array $attributes = []): array
    {
        $integration = Integration::factory()->create([
            'name' => 'Robot de SAP '.$company->slug,
            'abilities' => [Integration::KPIS_WRITE],
            'company_id' => $company->id,
            ...$attributes,
        ]);

        return [$integration, $integration->issueToken()];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_replace([
            'period_label' => 'Octubre',
            'as_of' => '2026-11-04T23:59:00-05:00',
            'source_label' => 'SAP Business One',
            'items' => [
                ['key' => 'ventas', 'label' => 'Ventas del mes', 'value' => 1250000000, 'unit' => 'COP', 'goal' => 1500000000, 'note' => 'Sin IVA'],
                ['key' => 'cartera-vencida', 'label' => 'Cartera vencida', 'value' => 12.5, 'unit' => '%'],
                ['key' => 'pedidos', 'label' => 'Pedidos', 'value' => 340],
            ],
        ], $overrides);
    }

    private function putKpis(array $body, ?string $token = null, array $server = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables($server)
            ->putJson('/api/v1/kpis', $body, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    /**
     * @return list<string>
     */
    private function keysOf(Company $company): array
    {
        return CompanyKpi::query()->where('company_id', $company->id)->orderBy('position')->pluck('key')->all();
    }

    // --- Replacing the set -----------------------------------------------------

    public function test_a_put_stores_the_whole_set_in_order(): void
    {
        $this->putKpis($this->body())
            ->assertOk()
            ->assertExactJson(['data' => ['items' => 3, 'as_of' => '2026-11-04T23:59:00-05:00']]);

        $this->assertSame(['ventas', 'cartera-vencida', 'pedidos'], $this->keysOf($this->segurex));

        $ventas = CompanyKpi::query()->where('key', 'ventas')->sole();
        $this->assertSame('Ventas del mes', $ventas->label);
        $this->assertSame(1250000000.0, $ventas->value);
        $this->assertSame(1500000000.0, $ventas->goal);
        $this->assertSame('COP', $ventas->unit);
        $this->assertSame('Sin IVA', $ventas->note);
        $this->assertSame('Octubre', $ventas->period_label);
        $this->assertSame('SAP Business One', $ventas->source_label);
        $this->assertTrue($ventas->as_of->equalTo(CarbonImmutable::parse('2026-11-05T04:59:00Z')));
        $this->assertSame($this->integration->id, $ventas->integration_id);

        $pedidos = CompanyKpi::query()->where('key', 'pedidos')->sole();
        $this->assertNull($pedidos->unit);
        $this->assertNull($pedidos->goal);
        $this->assertNull($pedidos->note);
    }

    public function test_the_next_put_replaces_every_item(): void
    {
        $this->putKpis($this->body())->assertOk();

        $this->putKpis($this->body([
            'period_label' => 'Noviembre',
            'items' => [['key' => 'dias-inventario', 'label' => 'Días de inventario', 'value' => 42, 'unit' => 'días']],
        ]))->assertOk()->assertJsonPath('data.items', 1);

        $this->assertSame(['dias-inventario'], $this->keysOf($this->segurex));
        $this->assertSame(['Noviembre'], CompanyKpi::query()->pluck('period_label')->all());
    }

    public function test_an_invalid_put_leaves_the_stored_set_untouched(): void
    {
        $this->putKpis($this->body())->assertOk();

        $this->putKpis($this->body(['items' => [['key' => 'ventas', 'label' => 'Ventas']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.value']);

        $this->assertSame(['ventas', 'cartera-vencida', 'pedidos'], $this->keysOf($this->segurex));
    }

    public function test_a_failure_while_writing_rolls_the_whole_replacement_back(): void
    {
        $this->putKpis($this->body())->assertOk();

        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'insert into "company_kpis"')) {
                throw new RuntimeException('Disk full');
            }
        });

        $this->putKpis($this->body(['items' => [['key' => 'otra', 'label' => 'Otra', 'value' => 1]]]))->assertServerError();
        Event::forget(QueryExecuted::class);

        $this->assertSame(['ventas', 'cartera-vencida', 'pedidos'], $this->keysOf($this->segurex));
    }

    public function test_every_replacement_first_locks_its_company(): void
    {
        $log = [];
        Event::listen(TransactionBeginning::class, function () use (&$log) {
            $log[] = 'begin';
        });
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });

        $this->putKpis($this->body())->assertOk();

        $begins = array_keys($log, 'begin', true);
        $first = $log[end($begins) + 1] ?? '';
        $this->assertStringContainsString('from "companies" where "companies"."id" = ?', $first, $first);
    }

    // --- The company comes only from the token -------------------------------

    public function test_a_token_of_one_company_never_writes_another(): void
    {
        [, $derivadosToken] = $this->kpiIntegration($this->derivados);
        $this->putKpis($this->body(['items' => [['key' => 'pedidos', 'label' => 'Pedidos', 'value' => 7]]]), $derivadosToken)->assertOk();

        $this->putKpis($this->body([
            'company_id' => $this->derivados->id,
            'company' => 'derivados',
            'tenant_id' => $this->derivados->tenant_id,
        ]))->assertOk();

        $this->assertSame(['ventas', 'cartera-vencida', 'pedidos'], $this->keysOf($this->segurex));
        $this->assertSame(['pedidos'], $this->keysOf($this->derivados));
        $this->assertSame(7.0, CompanyKpi::query()->where('company_id', $this->derivados->id)->sole()->value);
    }

    public function test_a_token_without_a_company_cannot_write(): void
    {
        [, $token] = $this->kpiIntegration($this->segurex, ['company_id' => null]);

        $this->putKpis($this->body(), $token)
            ->assertForbidden()
            ->assertJsonPath('message', 'This token is not bound to a company.');

        $this->assertSame(0, CompanyKpi::query()->count());
    }

    // --- Authentication -------------------------------------------------------

    public function test_the_endpoint_needs_a_live_token_with_kpis_write(): void
    {
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/kpis', $this->body())->assertUnauthorized();

        [, $other] = $this->kpiIntegration($this->segurex, ['abilities' => [Integration::CATALOG_READ, Integration::STATUS_WRITE, Integration::WORK_WRITE]]);
        $this->putKpis($this->body(), $other)->assertForbidden();

        $this->integration->revoke();
        $this->putKpis($this->body())->assertUnauthorized();

        $this->assertSame(0, CompanyKpi::query()->count());
    }

    public function test_the_ip_list_applies(): void
    {
        [, $token] = $this->kpiIntegration($this->segurex, ['allowed_ips' => ['10.0.0.5']]);

        $this->putKpis($this->body(), $token, ['REMOTE_ADDR' => '10.0.0.6'])->assertForbidden();
        $this->assertSame(0, CompanyKpi::query()->count());

        $this->putKpis($this->body(), $token, ['REMOTE_ADDR' => '10.0.0.5'])->assertOk();
    }

    public function test_calls_share_the_token_rate_limit(): void
    {
        $this->putKpis($this->body())
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', (string) Integration::REQUESTS_PER_MINUTE);

        $this->assertNotNull($this->integration->fresh()->last_used_at);
    }

    public function test_revoking_the_integration_deletes_the_figures_it_published(): void
    {
        [, $derivadosToken] = $this->kpiIntegration($this->derivados);
        $this->putKpis($this->body())->assertOk();
        $this->putKpis($this->body(), $derivadosToken)->assertOk();

        $this->integration->fresh()->revoke();

        $this->assertSame([], $this->keysOf($this->segurex));
        $this->assertCount(3, $this->keysOf($this->derivados));
    }

    // --- Audit and logs -------------------------------------------------------

    public function test_each_replacement_is_audited_with_the_count_and_never_the_values(): void
    {
        Log::spy();

        $this->putKpis($this->body())->assertOk();
        $this->putKpis($this->body(['items' => [['key' => 'ventas', 'label' => 'Ventas del mes', 'value' => 'mucho']]]))->assertUnprocessable();

        $entry = Activity::query()->where('log_name', Integration::AUDIT_LOG)->where('event', 'kpis.updated')->sole();
        $this->assertSame(['items' => 3], $entry->properties->all());
        $this->assertTrue($entry->causer->is($this->integration));
        $this->assertTrue($entry->subject->is($this->segurex));

        $stored = json_encode($entry->toArray());
        foreach (['1250000000', 'Ventas del mes', 'Sin IVA', 'SAP Business One', 'Octubre'] as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_the_replacement_shows_in_auditoria_under_api(): void
    {
        $admin = User::factory()->microsoft($this->segurex)->create();
        $this->seed(RoleSeeder::class);
        $admin->assignRole('super_admin');

        $this->putKpis($this->body())->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->get(route('admin.audit.index', ['tipo' => 'api']))
            ->assertSeeTextInOrder(['Robot de SAP segurex', 'API', 'Publicó las cifras del panel gerencial de la empresa Segurex Latam'])
            ->assertDontSeeText('1250000000');
    }

    // --- Validation -----------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        $item = ['key' => 'ventas', 'label' => 'Ventas', 'value' => 1];

        return [
            'no period' => [['period_label' => null], 'period_label'],
            'long period' => [['period_label' => str_repeat('a', 41)], 'period_label'],
            'period on two lines' => [['period_label' => "Octubre\nNoviembre"], 'period_label'],
            'no as_of' => [['as_of' => null], 'as_of'],
            'as_of without zone' => [['as_of' => '2026-11-04T23:59:00'], 'as_of'],
            'as_of only a date' => [['as_of' => '2026-11-04'], 'as_of'],
            'as_of impossible date' => [['as_of' => '2026-02-30T10:00:00-05:00'], 'as_of'],
            'as_of a number' => [['as_of' => 1730764740], 'as_of'],
            'no source' => [['source_label' => ''], 'source_label'],
            'long source' => [['source_label' => str_repeat('a', 81)], 'source_label'],
            'no items' => [['items' => []], 'items'],
            'items not a list' => [['items' => ['a' => $item]], 'items'],
            'nine items' => [['items' => array_map(fn (int $i) => [...$item, 'key' => "k{$i}"], range(1, 9))], 'items'],
            'item not an object' => [['items' => ['ventas']], 'items.0'],
            'no key' => [['items' => [[...$item, 'key' => null]]], 'items.0.key'],
            'key with capitals' => [['items' => [[...$item, 'key' => 'Ventas']]], 'items.0.key'],
            'key with spaces' => [['items' => [[...$item, 'key' => 'ventas mes']]], 'items.0.key'],
            'long key' => [['items' => [[...$item, 'key' => str_repeat('a', 41)]]], 'items.0.key'],
            'repeated key' => [['items' => [$item, [...$item, 'label' => 'Otra']]], 'items.1.key'],
            'no label' => [['items' => [[...$item, 'label' => '']]], 'items.0.label'],
            'long label' => [['items' => [[...$item, 'label' => str_repeat('a', 61)]]], 'items.0.label'],
            'label with a bidi override' => [['items' => [[...$item, 'label' => "Ventas\u{202E}"]]], 'items.0.label'],
            'no value' => [['items' => [[...$item, 'value' => null]]], 'items.0.value'],
            'value as text' => [['items' => [[...$item, 'value' => '12']]], 'items.0.value'],
            'value as boolean' => [['items' => [[...$item, 'value' => true]]], 'items.0.value'],
            'value too large' => [['items' => [[...$item, 'value' => 1e16]]], 'items.0.value'],
            'long unit' => [['items' => [[...$item, 'unit' => str_repeat('a', 17)]]], 'items.0.unit'],
            'goal as text' => [['items' => [[...$item, 'goal' => 'alta']]], 'items.0.goal'],
            'long note' => [['items' => [[...$item, 'note' => str_repeat('a', 121)]]], 'items.0.note'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_bodies_answer_422_with_the_field(array $overrides, string $field): void
    {
        $this->putKpis($this->body($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, CompanyKpi::query()->count());
    }

    public function test_error_messages_never_echo_the_values(): void
    {
        $secret = 'Ventas secretas 987654321 '.str_repeat('x', 60);

        $response = $this->putKpis($this->body(['items' => [['key' => 'ventas', 'label' => $secret, 'value' => 987654321]]]))
            ->assertUnprocessable();

        $this->assertStringNotContainsString('987654321', $response->getContent());
    }

    public function test_limits_and_valid_edges_are_accepted(): void
    {
        $items = array_map(fn (int $i) => [
            'key' => str_pad("k{$i}", 40, 'x'),
            'label' => str_repeat('é', 60),
            'value' => $i === 1 ? -999999999999999 : 0.25,
            'unit' => str_repeat('u', 16),
            'goal' => 0,
            'note' => str_repeat('n', 120),
        ], range(1, 8));

        $this->putKpis($this->body([
            'period_label' => str_repeat('p', 40),
            'as_of' => '2026-11-05T04:59Z',
            'source_label' => str_repeat('s', 80),
            'items' => $items,
        ]))->assertOk()->assertJsonPath('data.items', 8);

        $this->putKpis($this->body(['items' => [['key' => 'a_b-1', 'label' => 'Uno', 'value' => 1, 'unit' => null, 'goal' => null, 'note' => null]]]))
            ->assertOk();
    }

    public function test_a_body_over_8_kb_answers_413(): void
    {
        $this->putKpis($this->body(['source_label' => str_repeat('a', CompanyKpi::MAX_BODY_BYTES)]))->assertStatus(413);

        $this->assertSame(0, CompanyKpi::query()->count());
    }
}
