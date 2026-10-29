<?php

namespace Tests\Feature\Api;

use App\Models\App;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\WorkItemLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Inbound side of docs/contrato-tareas-aprobaciones.md (§3, §4, §9):
 * the tasks app publishes its work items with PUT and DELETE
 * /api/v1/work-items/{external_id} (ability `work:write`).
 */
class WorkItemsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '6f1c2a9e-0000-4000-8000-000000000001';

    private const OID = '0b7d5e3a-0000-4000-8000-000000000002';

    private const ID = '8812:0b7d5e3a-0000-4000-8000-000000000002';

    private Company $segurex;

    private App $tareas;

    private Integration $integration;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.allowed_app_hosts' => ['segurex.com.co']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        activity()->withoutLogs(function () {
            $this->segurex = Company::factory()->forTenant(self::TENANT)->create(['slug' => 'segurex']);
            $this->tareas = App::factory()->create([
                'slug' => 'tareas',
                'name' => 'Tareas y aprobaciones',
                'url' => 'https://tareas.segurex.com.co',
                'login_type' => 'microsoft',
            ]);
        });

        [$this->integration, $this->token] = $this->workIntegration();
    }

    /**
     * @return array{0: Integration, 1: string}
     */
    private function workIntegration(array $attributes = []): array
    {
        $integration = Integration::factory()->create([
            'name' => 'App de tareas y aprobaciones',
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $this->tareas->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
            ...$attributes,
        ]);

        return [$integration, $integration->issueToken()];
    }

    private function task(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'task',
            'title' => 'Enviar el acta de la reunión de HSE',
            'status' => 'pending',
            'due_at' => '2026-11-05T17:00:00-05:00',
            'url' => 'https://tareas.segurex.com.co/tareas/8812',
            'completable_from_portal' => true,
            'version' => 3,
            'assignee' => ['tenant_id' => self::TENANT, 'oid' => self::OID],
        ], $overrides);
    }

    private function putItem(array $body, string $id = self::ID, ?string $token = null, array $server = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables($server)
            ->putJson('/api/v1/work-items/'.$id, $body, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function deleteItem(string $id = self::ID, ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->deleteJson('/api/v1/work-items/'.$id, [], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function item(): WorkItem
    {
        return WorkItem::withTrashed()->where('external_id', self::ID)->sole();
    }

    // --- Creating and updating -----------------------------------------------

    public function test_a_new_task_is_created_with_201(): void
    {
        $this->putItem($this->task(['extra' => 'ignored']))
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['data' => ['external_id' => self::ID, 'result' => 'created', 'version' => 3]]);

        $item = $this->item();
        $this->assertTrue($item->integration->is($this->integration));
        $this->assertSame(['task', 'Enviar el acta de la reunión de HSE', 'pending', 3, true], [$item->kind, $item->title, $item->status, $item->version, $item->completable_from_portal]);
        $this->assertSame('https://tareas.segurex.com.co/tareas/8812', $item->url);
        $this->assertTrue($item->due_at->eq(CarbonImmutable::parse('2026-11-05T22:00:00Z')));
        $this->assertSame([self::TENANT, self::OID], [$item->assignee_tenant_id, $item->assignee_oid]);
        $this->assertArrayNotHasKey('extra', $item->getAttributes());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dueDatesInOtherZones(): array
    {
        return [
            'UTC' => ['2026-11-05T22:00:00Z', '2026-11-05T22:00:00Z'],
            'east of UTC' => ['2026-11-05T22:00:00+02:00', '2026-11-05T20:00:00Z'],
            'Bogotá' => ['2026-11-05T17:00:00-05:00', '2026-11-05T22:00:00Z'],
        ];
    }

    #[DataProvider('dueDatesInOtherZones')]
    public function test_due_at_keeps_the_instant_whatever_the_time_zone(string $dueAt, string $instant): void
    {
        $this->putItem($this->task(['due_at' => $dueAt]))->assertCreated();

        $stored = WorkItem::query()->sole()->due_at;
        $this->assertTrue($stored->eq(CarbonImmutable::parse($instant)), "{$dueAt} was stored as {$stored->toIso8601String()}");
    }

    public function test_an_approval_without_due_date_is_created(): void
    {
        $this->putItem($this->task([
            'kind' => 'approval',
            'title' => 'Compra de EPP para la bodega de Funza',
            'due_at' => null,
            'completable_from_portal' => false,
            'version' => 1,
        ]))->assertCreated()->assertJsonPath('data.result', 'created');

        $item = $this->item();
        $this->assertSame('approval', $item->kind);
        $this->assertNull($item->due_at);
        $this->assertFalse($item->completable_from_portal);
    }

    public function test_guids_are_stored_lowercase_and_the_title_trimmed(): void
    {
        $this->putItem($this->task([
            'title' => '   Revisar inventario  ',
            'assignee' => ['tenant_id' => strtoupper(self::TENANT), 'oid' => strtoupper(self::OID)],
        ]))->assertCreated();

        $item = $this->item();
        $this->assertSame('Revisar inventario', $item->title);
        $this->assertSame([self::TENANT, self::OID], [$item->assignee_tenant_id, $item->assignee_oid]);
    }

    public function test_a_higher_version_replaces_every_field(): void
    {
        $this->putItem($this->task())->assertCreated();

        $this->putItem($this->task(['title' => 'Acta enviada', 'status' => 'done', 'due_at' => null, 'completable_from_portal' => false, 'version' => 4]))
            ->assertOk()
            ->assertExactJson(['data' => ['external_id' => self::ID, 'result' => 'updated', 'version' => 4]]);

        $item = $this->item();
        $this->assertSame(['Acta enviada', 'done', 4, false], [$item->title, $item->status, $item->version, $item->completable_from_portal]);
        $this->assertNull($item->due_at);
    }

    public function test_the_same_version_changes_nothing_even_with_other_content(): void
    {
        $this->putItem($this->task())->assertCreated();

        $this->putItem($this->task(['title' => 'Otro título']))
            ->assertOk()
            ->assertExactJson(['data' => ['external_id' => self::ID, 'result' => 'unchanged', 'version' => 3]]);

        $this->assertSame('Enviar el acta de la reunión de HSE', $this->item()->title);
    }

    public function test_a_lower_version_is_ignored_and_answers_the_stored_one(): void
    {
        $this->putItem($this->task(['version' => 7]))->assertCreated();

        $this->putItem($this->task(['title' => 'Viejo', 'version' => 5]))
            ->assertOk()
            ->assertExactJson(['data' => ['external_id' => self::ID, 'result' => 'ignored_stale', 'version' => 7]]);

        $this->assertSame('Enviar el acta de la reunión de HSE', $this->item()->title);
    }

    public function test_the_kind_and_the_assignee_cannot_change(): void
    {
        $this->putItem($this->task())->assertCreated();

        $this->putItem($this->task(['kind' => 'approval', 'completable_from_portal' => false, 'version' => 4]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind']);

        $this->putItem($this->task(['assignee' => ['tenant_id' => self::TENANT, 'oid' => '5c2e8d14-0000-4000-8000-000000000003'], 'version' => 4]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee']);

        $this->assertSame(3, $this->item()->version);
    }

    // --- Deleting -------------------------------------------------------------

    public function test_delete_answers_204_whether_or_not_the_item_exists(): void
    {
        $this->deleteItem()->assertNoContent();

        $this->putItem($this->task())->assertCreated();
        $this->deleteItem()->assertNoContent();
        $this->deleteItem()->assertNoContent();

        $this->assertSame(0, WorkItem::query()->count());

        // Only the version trace is left.
        $trace = $this->item();
        $this->assertTrue($trace->trashed());
        $this->assertSame(3, $trace->version);
        $this->assertNull($trace->title);
        $this->assertNull($trace->url);
        $this->assertNull($trace->assignee_oid);
    }

    public function test_a_deleted_item_is_not_revived_by_an_old_or_equal_version(): void
    {
        $this->putItem($this->task(['version' => 5]))->assertCreated();
        $this->deleteItem()->assertNoContent();

        $this->putItem($this->task(['version' => 5]))->assertOk()->assertJsonPath('data.result', 'ignored_stale')->assertJsonPath('data.version', 5);
        $this->putItem($this->task(['version' => 4]))->assertOk()->assertJsonPath('data.result', 'ignored_stale');
        $this->assertSame(0, WorkItem::query()->count());

        // A higher version creates it again, even with another kind.
        $this->putItem($this->task(['kind' => 'approval', 'completable_from_portal' => false, 'version' => 6]))
            ->assertCreated()
            ->assertJsonPath('data.result', 'created');
        $this->assertSame('approval', WorkItem::query()->sole()->kind);
    }

    public function test_the_trace_of_a_deleted_item_lasts_30_days(): void
    {
        $this->putItem($this->task(['version' => 5]))->assertCreated();
        $this->deleteItem()->assertNoContent();

        $this->travel(WorkItem::RETENTION_DAYS)->days();
        $this->travel(1)->minute();

        $this->putItem($this->task(['version' => 1]))->assertCreated()->assertJsonPath('data.result', 'created');
        $this->assertSame(1, WorkItem::query()->sole()->version);
    }

    public function test_old_traces_and_finished_items_are_pruned(): void
    {
        $this->putItem($this->task())->assertCreated();
        $this->putItem($this->task(['version' => 1]), 'borrada')->assertCreated();
        $this->deleteItem('borrada')->assertNoContent();
        $this->putItem($this->task(['status' => 'done', 'version' => 1]), 'hecha')->assertCreated();
        $this->putItem($this->task(['status' => 'cancelled', 'version' => 1]), 'cancelada')->assertCreated();

        $this->travel(WorkItem::RETENTION_DAYS + 1)->days();
        $this->putItem($this->task(['status' => 'done', 'version' => 1]), 'hecha-hoy')->assertCreated();

        $this->artisan('model:prune', ['--model' => [WorkItem::class]])->assertSuccessful();

        $this->assertEqualsCanonicalizing([self::ID, 'hecha-hoy'], WorkItem::withTrashed()->pluck('external_id')->all());
    }

    // --- Validation -----------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'kind missing' => [['kind' => null], 'kind'],
            'kind unknown' => [['kind' => 'note'], 'kind'],
            'title missing' => [['title' => null], 'title'],
            'title blank' => [['title' => '   '], 'title'],
            'title too long' => [['title' => str_repeat('a', 141)], 'title'],
            'title with a line break' => [['title' => "Primera\nsegunda"], 'title'],
            'title with a tab' => [['title' => "Con\ttabulador"], 'title'],
            'title with a control character' => [['title' => "Con\x07campana"], 'title'],
            'title with a line separator' => [['title' => "Con\u{2028}separador"], 'title'],
            'title with a bidi override' => [['title' => "Factura \u{202E}fdp.exe"], 'title'],
            'title with a left-to-right mark' => [['title' => "Factura \u{200E}uno"], 'title'],
            'title with a right-to-left mark' => [['title' => "Factura \u{200F}uno"], 'title'],
            'title with an Arabic letter mark' => [['title' => "Factura \u{061C}uno"], 'title'],
            'title with a zero-width space' => [['title' => "Fac\u{200B}tura"], 'title'],
            // Inside the title: TrimStrings already strips them from the edges.
            'title with a byte order mark' => [['title' => "Fac\u{FEFF}tura"], 'title'],
            'title not a string' => [['title' => ['a']], 'title'],
            'status unknown' => [['status' => 'open'], 'status'],
            'due_at without time zone' => [['due_at' => '2026-11-05T17:00:00'], 'due_at'],
            'due_at only a date' => [['due_at' => '2026-11-05'], 'due_at'],
            'due_at impossible' => [['due_at' => '2026-02-30T17:00:00-05:00'], 'due_at'],
            'due_at not a date' => [['due_at' => 'mañana'], 'due_at'],
            'url missing' => [['url' => null], 'url'],
            'completable missing' => [['completable_from_portal' => null], 'completable_from_portal'],
            'completable as text' => [['completable_from_portal' => 'true'], 'completable_from_portal'],
            'completable as number' => [['completable_from_portal' => 1], 'completable_from_portal'],
            'completable approval' => [['kind' => 'approval', 'completable_from_portal' => true], 'completable_from_portal'],
            'version missing' => [['version' => null], 'version'],
            'version zero' => [['version' => 0], 'version'],
            'version negative' => [['version' => -1], 'version'],
            'version too big' => [['version' => 9007199254740992], 'version'],
            'version decimal' => [['version' => 1.5], 'version'],
            'version as text' => [['version' => '3'], 'version'],
            'assignee missing' => [['assignee' => null], 'assignee'],
            'assignee tenant not a guid' => [['assignee' => ['tenant_id' => 'segurex', 'oid' => self::OID]], 'assignee.tenant_id'],
            'assignee oid missing' => [['assignee' => ['tenant_id' => self::TENANT]], 'assignee.oid'],
            'assignee tenant of no company' => [['assignee' => ['tenant_id' => '11111111-0000-4000-8000-000000000009', 'oid' => self::OID]], 'assignee'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_fields_answer_422_with_the_field(array $overrides, string $field): void
    {
        $this->putItem($this->task($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, WorkItem::withTrashed()->count());
    }

    public function test_limits_and_valid_edges_are_accepted(): void
    {
        $this->putItem($this->task(['title' => str_repeat('á', 140), 'version' => 9007199254740991]))
            ->assertCreated()
            ->assertJsonPath('data.version', 9007199254740991);
        $this->assertSame(9007199254740991, $this->item()->version);

        $this->putItem($this->task(['due_at' => '2026-11-05T22:00:00Z']), 'utc')->assertCreated();
        $this->putItem(array_diff_key($this->task(), ['due_at' => true]), 'sin-fecha')->assertCreated();
    }

    public function test_a_body_over_8_kb_answers_413(): void
    {
        $this->putItem($this->task(['padding' => str_repeat('x', WorkItem::MAX_BODY_BYTES)]))
            ->assertStatus(413)
            ->assertJsonStructure(['message']);

        $this->assertSame(0, WorkItem::withTrashed()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidExternalIds(): array
    {
        return [
            'starts with a dash' => ['-8812'],
            'starts with a dot' => ['.8812'],
            'accented letter' => ['tarea-ñ'],
            'space' => ['tarea%20uno'],
            'too long' => [str_repeat('a', 191)],
            'slash' => ['a%2Fb'],
        ];
    }

    #[DataProvider('invalidExternalIds')]
    public function test_an_external_id_with_other_characters_answers_404(string $id): void
    {
        $this->putItem($this->task(), $id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->deleteItem($id)->assertNotFound();
    }

    public function test_a_190_character_external_id_works_and_colons_may_come_encoded(): void
    {
        $this->putItem($this->task(), str_repeat('a', 190))->assertCreated();
        $this->putItem($this->task(), '8812%3Aotra')->assertCreated()->assertJsonPath('data.external_id', '8812:otra');
    }

    // --- Links ----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://tareas.segurex.com.co/tareas/1'],
            'another host' => ['https://evil.example.com/tareas/1'],
            'a subdomain' => ['https://otra.tareas.segurex.com.co/tareas/1'],
            'a parent domain' => ['https://segurex.com.co/tareas/1'],
            'user info pointing elsewhere' => ['https://tareas.segurex.com.co@evil.example.com/x'],
            'user info on the host' => ['https://user@tareas.segurex.com.co/x'],
            'user and password' => ['https://user:pass@tareas.segurex.com.co/x'],
            'backslash trick' => ['https://evil.example.com\\@tareas.segurex.com.co/x'],
            'backslashes after the scheme' => ['https:\\\\evil.example.com/x'],
            'a percent-encoded host' => ['https://tareas%2Esegurex.com.co/x'],
            'a percent-encoded host letter' => ['https://%74areas.segurex.com.co/x'],
            'an empty host' => ['https:///tareas.segurex.com.co/x'],
            'port 443 written oddly' => ['https://tareas.segurex.com.co:0443/tareas/1'],
            'an empty port' => ['https://tareas.segurex.com.co:/tareas/1'],
            'trailing dot' => ['https://tareas.segurex.com.co./tareas/1'],
            'another port' => ['https://tareas.segurex.com.co:8443/tareas/1'],
            'javascript' => ['javascript:alert(1)'],
            'scheme relative' => ['//tareas.segurex.com.co/tareas/1'],
            'with a space' => ['https://tareas.segurex.com.co/tareas uno'],
            'too long' => ['https://tareas.segurex.com.co/'.str_repeat('a', 2048)],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_links_must_be_https_on_the_registered_app_host(string $url): void
    {
        $this->putItem($this->task(['url' => $url]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_the_host_is_compared_without_case(): void
    {
        $this->putItem($this->task(['url' => 'https://TAREAS.Segurex.com.co/tareas/8812?vista=1#detalle']))->assertCreated();
    }

    public function test_an_at_sign_after_the_host_is_allowed(): void
    {
        $this->putItem($this->task(['url' => 'https://tareas.segurex.com.co/personas/jhon@segurex.com.co?de=a@b#c@d']))->assertCreated();
    }

    public function test_an_explicit_port_443_equals_no_port(): void
    {
        $this->putItem($this->task(['url' => 'https://tareas.segurex.com.co:443/tareas/8812']))->assertCreated();

        $this->putItem($this->task(['url' => 'https://TAREAS.segurex.com.co:443/tareas/2']), 'mayusculas')->assertCreated();
        $this->putItem($this->task(['url' => 'https://tareas.segurex.com.co:8443/tareas/3']), 'otro-puerto')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        // The catalog only takes URLs without a port today; a `:443` there
        // would still mean the same host.
        $this->assertSame('tareas.segurex.com.co', WorkItemLink::authorityOf('https://tareas.segurex.com.co:443'));
        $this->assertNull(WorkItemLink::problem('https://tareas.segurex.com.co/x', WorkItemLink::authorityOf('https://tareas.segurex.com.co:443')));
        $this->assertSame('tareas.segurex.com.co:8443', WorkItemLink::authorityOf('https://tareas.segurex.com.co:8443'));
    }

    public function test_the_host_rule_is_checked_on_every_put(): void
    {
        $this->putItem($this->task())->assertCreated();

        $this->putItem($this->task(['url' => 'https://evil.example.com/x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_without_a_catalog_app_with_url_no_link_is_valid(): void
    {
        $this->integration->forceFill(['app_id' => null])->save();

        $this->putItem($this->task())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url' => 'The integration has no catalog app with a url.']);
    }

    // --- 500 pending per person ----------------------------------------------

    public function test_a_person_may_have_500_pending_items_per_integration(): void
    {
        WorkItem::factory()->count(WorkItem::MAX_PENDING_PER_PERSON)->for($this->integration)->create([
            'assignee_tenant_id' => self::TENANT,
            'assignee_oid' => self::OID,
        ]);
        WorkItem::query()->first()->forceFill(['external_id' => 'existente'])->save();

        $this->putItem($this->task())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee']);

        // Finished items, updates of a pending one and other people still go.
        $this->putItem($this->task(['status' => 'done']))->assertCreated();
        $this->putItem($this->task(['title' => 'Cambiada', 'version' => 99]), 'existente')->assertOk()->assertJsonPath('data.result', 'updated');
        $this->putItem($this->task(['assignee' => ['tenant_id' => self::TENANT, 'oid' => '5c2e8d14-0000-4000-8000-000000000003']]), 'otra-persona')->assertCreated();

        // A done item cannot come back to pending over the limit.
        $this->putItem($this->task(['version' => 4]))->assertUnprocessable()->assertJsonValidationErrors(['assignee']);

        // Another integration has its own budget.
        [, $other] = $this->workIntegration(['name' => 'Otra app']);
        $this->putItem($this->task(), self::ID, $other)->assertCreated();
    }

    // --- Isolation ------------------------------------------------------------

    public function test_an_integration_never_sees_nor_changes_the_items_of_another(): void
    {
        [$other, $otherToken] = $this->workIntegration(['name' => 'Otra app']);

        $this->putItem($this->task(['version' => 5]))->assertCreated();
        $this->putItem($this->task(['title' => 'De la otra', 'version' => 1]), self::ID, $otherToken)
            ->assertCreated()
            ->assertJsonPath('data.result', 'created');

        $this->deleteItem(self::ID, $otherToken)->assertNoContent();

        $mine = WorkItem::query()->sole();
        $this->assertTrue($mine->integration->is($this->integration));
        $this->assertSame([5, 'Enviar el acta de la reunión de HSE'], [$mine->version, $mine->title]);
        $this->assertTrue(WorkItem::onlyTrashed()->sole()->integration->is($other));
    }

    // --- Authentication -------------------------------------------------------

    public function test_the_endpoints_need_a_live_token_with_work_write(): void
    {
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/work-items/'.self::ID, $this->task())->assertUnauthorized();
        $this->deleteJson('/api/v1/work-items/'.self::ID)->assertUnauthorized();

        [, $statusOnly] = $this->workIntegration(['abilities' => [Integration::STATUS_WRITE, Integration::CATALOG_READ]]);
        $this->putItem($this->task(), self::ID, $statusOnly)->assertForbidden();
        $this->deleteItem(self::ID, $statusOnly)->assertForbidden();

        $this->integration->revoke();
        $this->putItem($this->task())->assertUnauthorized();

        $this->assertSame(0, WorkItem::withTrashed()->count());
    }

    public function test_the_ip_list_applies(): void
    {
        [, $token] = $this->workIntegration(['allowed_ips' => ['10.0.0.5']]);

        $this->putItem($this->task(), self::ID, $token, ['REMOTE_ADDR' => '10.0.0.6'])->assertForbidden();
        $this->putItem($this->task(), self::ID, $token, ['REMOTE_ADDR' => '10.0.0.5'])->assertCreated();
    }

    public function test_calls_share_the_token_rate_limit_and_leave_no_audit_entry(): void
    {
        $this->putItem($this->task())
            ->assertCreated()
            ->assertHeader('X-RateLimit-Limit', (string) Integration::REQUESTS_PER_MINUTE);
        $this->deleteItem()->assertNoContent();

        $this->assertSame(0, Activity::query()->where('log_name', Integration::AUDIT_LOG)->count());
        $this->assertNotNull($this->integration->fresh()->last_used_at);
    }

    public function test_revoking_the_integration_deletes_its_items(): void
    {
        $this->putItem($this->task())->assertCreated();
        $this->putItem($this->task(), 'borrada')->assertCreated();
        $this->deleteItem('borrada');

        $this->integration->fresh()->revoke();

        $this->assertSame(0, WorkItem::withTrashed()->count());
    }

    // --- Visibility (§4) ------------------------------------------------------

    public function test_only_the_pending_items_of_the_active_account_are_visible(): void
    {
        $person = User::factory()->microsoft($this->segurex)->create(['azure_oid' => self::OID]);
        $derivados = Company::factory()->create(['slug' => 'derivados']);
        $sameEmailElsewhere = User::factory()->microsoft($derivados)->create(['azure_oid' => self::OID]);
        $emergency = User::factory()->emergency()->create();

        $this->putItem($this->task())->assertCreated();
        $this->putItem($this->task(['status' => 'done']), 'hecha')->assertCreated();
        $this->putItem($this->task(), 'borrada')->assertCreated();
        $this->deleteItem('borrada');
        $this->putItem($this->task(['assignee' => ['tenant_id' => self::TENANT, 'oid' => '5c2e8d14-0000-4000-8000-000000000003']]), 'de-otra')->assertCreated();
        [$revoked, $revokedToken] = $this->workIntegration(['name' => 'Revocada']);
        $this->putItem($this->task(), 'de-revocada', $revokedToken)->assertCreated();
        $revoked->forceFill(['revoked_at' => now()])->save();

        $this->assertSame([self::ID], WorkItem::query()->visibleTo($person)->pluck('external_id')->all());
        $this->assertSame([], WorkItem::query()->visibleTo($sameEmailElsewhere)->pluck('external_id')->all());
        $this->assertSame([], WorkItem::query()->visibleTo($emergency)->pluck('external_id')->all());
    }

    public function test_items_of_an_integration_without_work_write_or_app_are_hidden_until_restored(): void
    {
        $person = User::factory()->microsoft($this->segurex)->create(['azure_oid' => self::OID]);
        $this->putItem($this->task())->assertCreated();
        $visible = fn () => WorkItem::query()->visibleTo($person)->pluck('external_id')->all();

        $this->integration->changeAbilities([Integration::CATALOG_READ]);
        $this->assertSame([], $visible());

        $this->integration->changeAbilities([Integration::WORK_WRITE]);
        $this->assertSame([self::ID], $visible());

        $this->integration->forceFill(['app_id' => null])->save();
        $this->assertSame([], $visible());

        $this->integration->forceFill(['app_id' => $this->tareas->id])->save();
        $this->assertSame([self::ID], $visible());

        // Deleting the catalog app leaves the integration without one.
        $this->tareas->delete();
        $this->assertSame([], $visible());
    }

    // --- Concurrency ----------------------------------------------------------

    public function test_every_write_first_locks_its_integration(): void
    {
        $this->putItem($this->task())->assertCreated();

        foreach (['put' => fn () => $this->putItem($this->task(['version' => 4]))->assertOk(), 'delete' => fn () => $this->deleteItem()->assertNoContent()] as $call => $write) {
            $log = [];
            Event::listen(TransactionBeginning::class, function () use (&$log) {
                $log[] = 'begin';
            });
            DB::listen(function (QueryExecuted $query) use (&$log) {
                $log[] = $query->sql;
            });

            $write();

            $first = $log[array_search('begin', $log, true) + 1] ?? '';
            $this->assertStringContainsString('from "integrations" where "integrations"."id" = ?', $first, "{$call}: {$first}");
            Event::forget(TransactionBeginning::class);
            Event::forget(QueryExecuted::class);
        }

        $this->assertSame(4, $this->item()->version);
        $this->assertTrue($this->item()->trashed());
    }
}
