<?php

namespace Tests\Feature\Work;

use App\Http\Controllers\WorkItemCompletionController;
use App\Models\App;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\CompletionOutcome;
use App\Work\TaskCompleter;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Completar» (docs/contrato-tareas-aprobaciones.md §6): the signed-in
 * account completes one of its own pending tasks; the portal asks the tasks
 * app with a signed server-to-server call and only marks the task done
 * once the app confirms. Never the network: every call is faked.
 */
class CompleteTaskTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '6f1c2a9e-0000-4000-8000-000000000001';

    private const EXTERNAL_ID = '8812:0b7d5e3a-0000-4000-8000-000000000002';

    private const CALL_URL = 'https://tareas.segurex.com.co/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete';

    private Company $segurex;

    private App $tareas;

    private User $user;

    private Integration $integration;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));

        $this->segurex = Company::factory()->forTenant(self::TENANT)->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->tareas = App::factory()->create(['slug' => 'tareas', 'name' => 'Tareas y aprobaciones', 'url' => 'https://tareas.segurex.com.co']);
        $this->user = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
            'azure_oid' => '0B7D5E3A-0000-4000-8000-000000000002',
        ]);
        $this->integration = Integration::factory()->create([
            'name' => 'App de tareas y aprobaciones',
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $this->tareas->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
        $this->secret = $this->integration->issueSigningSecret();
    }

    private function task(array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($this->integration)->create([
            'external_id' => self::EXTERNAL_ID,
            'title' => 'Enviar el acta de la reunión de HSE',
            'version' => 3,
            'assignee_tenant_id' => self::TENANT,
            'assignee_oid' => strtolower($this->user->azure_oid),
            ...$attributes,
        ]);
    }

    private function complete(WorkItem $item, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->post(route('work-items.complete', $item));
    }

    private function appAnswers(mixed $response): void
    {
        Http::fake(['tareas.segurex.com.co/*' => $response]);
    }

    private function entry(string $event): Activity
    {
        return Activity::query()->where('log_name', Integration::AUDIT_LOG)->where('event', $event)->sole();
    }

    // --- The call ---------------------------------------------------------------

    public function test_completing_signs_the_call_and_marks_the_task_done_once_the_app_confirms(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'completed']));

        $this->complete($item)
            ->assertRedirect(route('home').'#tareas')
            ->assertSessionHas('work_status', 'Tarea completada.')
            ->assertSessionMissing('status');

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) {
            $timestamp = (string) now()->getTimestamp();
            $path = '/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete';
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === self::CALL_URL
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->header('X-Portal-Timestamp') === [$timestamp]
                && $request->header('X-Portal-Signature') === ['sha256='.hash_hmac('sha256', $timestamp."\nPOST\n".$path."\n".$request->body(), $this->secret)]
                && array_keys($body) === ['assignee', 'completed_at', 'request_id']
                && $body['assignee'] === ['tenant_id' => self::TENANT, 'oid' => '0b7d5e3a-0000-4000-8000-000000000002']
                && $body['completed_at'] === '2026-11-04T09:00:00-05:00'
                && Str::isUuid($body['request_id'])
                // Exactly as signed: no escaped slashes, one line.
                && ! str_contains($request->body(), '\\/') && ! str_contains($request->body(), "\n");
        });

        $item->refresh();
        $this->assertSame(WorkItem::STATUS_DONE, $item->status);
        // §3.3 point 5: the portal never changes the version.
        $this->assertSame(3, $item->version);
        $this->assertSame(0, WorkItem::query()->visibleTo($this->user)->count());
    }

    public function test_a_new_request_id_on_every_press(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'not_completable'], 403));

        $this->complete($item);
        $this->complete($item);

        $ids = [];
        Http::assertSent(function (ClientRequest $request) use (&$ids) {
            $ids[] = json_decode($request->body(), true)['request_id'];

            return true;
        });

        $this->assertCount(2, array_unique($ids));
    }

    public function test_already_done_counts_as_completed(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'already_done'], 409));

        $this->complete($item)->assertSessionHas('work_status', 'Tarea completada.');

        $this->assertSame(WorkItem::STATUS_DONE, $item->fresh()->status);
        $this->assertSame(409, $this->entry('work_item.completed')->properties['attributes']['http_status']);
    }

    public function test_a_forbidden_answer_keeps_the_task_pending(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'not_assignee'], 403));

        $this->complete($item)
            ->assertRedirect(route('home').'#tareas')
            ->assertSessionHas('work_status', 'No puedes completar esta tarea desde el portal. Ábrela en la app.');

        $this->assertSame(WorkItem::STATUS_PENDING, $item->fresh()->status);
        $this->assertSame('forbidden', $this->entry('work_item.complete_failed')->properties['attributes']['reason']);
    }

    public function test_not_found_in_the_app_removes_it_from_the_portal_keeping_its_version(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'not_found'], 404));

        $this->complete($item)->assertSessionHas('work_status', 'Esta tarea ya no existe en la app.');

        $trace = WorkItem::withTrashed()->sole();
        $this->assertTrue($trace->trashed());
        $this->assertSame(3, $trace->version);
        $this->assertNull($trace->title);
        $this->assertSame('not_found', $this->entry('work_item.complete_failed')->properties['attributes']['reason']);
    }

    /**
     * @return array<string, array{\Closure(): mixed, int|string, string}>
     */
    public static function failedAnswers(): array
    {
        return [
            '500' => [fn () => Http::response('', 500), 500, 'server_error'],
            '503' => [fn () => Http::response(['code' => 'completed'], 503), 503, 'server_error'],
            'no answer in 5 s' => [fn () => Http::failedConnection(), 'timeout', 'timeout'],
            'a redirect' => [fn () => Http::response('', 302, ['Location' => 'https://otro.example.com/']), 302, 'unexpected_response'],
            'bad signature' => [fn () => Http::response(['code' => 'invalid_signature'], 401), 401, 'unexpected_response'],
            'bad request' => [fn () => Http::response('', 400), 400, 'unexpected_response'],
            'invalid' => [fn () => Http::response('', 422), 422, 'unexpected_response'],
            '409 without already_done' => [fn () => Http::response(['code' => 'conflict'], 409), 409, 'unexpected_response'],
            '409 without a body' => [fn () => Http::response('', 409), 409, 'unexpected_response'],
            // §6.4 as written: only the codes of §6.3 count.
            '200 without a code' => [fn () => Http::response('', 200), 200, 'unexpected_response'],
            '200 with another code' => [fn () => Http::response(['code' => 'done']), 200, 'unexpected_response'],
            '201' => [fn () => Http::response(['code' => 'completed'], 201), 201, 'unexpected_response'],
            '202' => [fn () => Http::response(['code' => 'completed'], 202), 202, 'unexpected_response'],
            '204' => [fn () => Http::response('', 204), 204, 'unexpected_response'],
            '404 without a body' => [fn () => Http::response('', 404), 404, 'unexpected_response'],
            '404 from a web server' => [fn () => Http::response('<html><body>Not Found</body></html>', 404, ['Content-Type' => 'text/html']), 404, 'unexpected_response'],
            '403 without a code' => [fn () => Http::response('<html>Forbidden</html>', 403), 403, 'unexpected_response'],
        ];
    }

    #[DataProvider('failedAnswers')]
    public function test_any_other_answer_keeps_the_task_pending(\Closure $answer, int|string $status, string $reason): void
    {
        $item = $this->task();
        $this->appAnswers($answer());

        $this->complete($item)
            ->assertRedirect(route('home').'#tareas')
            ->assertSessionHas('work_status', 'No se pudo completar. Inténtalo de nuevo o ábrela en la app.');

        // Never retried, never redirected, never removed.
        Http::assertSentCount(1);
        $this->assertFalse($item->fresh()->trashed());
        $this->assertSame(WorkItem::STATUS_PENDING, $item->fresh()->status);
        $this->assertSame(3, $item->fresh()->version);

        $failed = $this->entry('work_item.complete_failed')->properties['attributes'];
        $this->assertSame($status, $failed['http_status']);
        $this->assertSame($reason, $failed['reason']);
    }

    public function test_the_call_waits_at_most_five_seconds_and_follows_no_redirects(): void
    {
        $item = $this->task();
        $options = null;
        Http::fake(function (ClientRequest $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['code' => 'completed']);
        });

        $this->complete($item);

        $this->assertSame(5, $options['timeout'] ?? null);
        $this->assertSame(5, $options['connect_timeout'] ?? null);
        $this->assertFalse($options['allow_redirects'] ?? null);
        // TLS is always verified.
        $this->assertNotFalse($options['verify'] ?? true);
    }

    // --- What is refused before calling ------------------------------------------

    public function test_an_approval_is_never_completed_and_the_app_is_not_called(): void
    {
        $approval = $this->task(['kind' => WorkItem::KIND_APPROVAL, 'completable_from_portal' => false]);
        // Even if the flag was set some other way.
        $flagged = $this->task(['external_id' => 'A-2', 'kind' => WorkItem::KIND_APPROVAL, 'completable_from_portal' => true]);

        $this->complete($approval)->assertForbidden();
        $this->complete($flagged)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Activity::query()->where('log_name', Integration::AUDIT_LOG)->count());
    }

    public function test_a_task_not_completable_from_the_portal_is_refused(): void
    {
        $item = $this->task(['completable_from_portal' => false]);

        $this->complete($item)->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{\Closure(self): array<string, mixed>}>
     */
    public static function integrationsNotReady(): array
    {
        return [
            'no signing secret' => [fn () => ['signing_secret' => null]],
            'no base URL' => [fn () => ['app_base_url' => null]],
            'a base URL on another host' => [fn () => ['app_base_url' => 'https://otro.segurex.com.co']],
            'a base URL on a subdomain' => [fn () => ['app_base_url' => 'https://api.tareas.segurex.com.co']],
            'a base URL on another port' => [fn () => ['app_base_url' => 'https://tareas.segurex.com.co:8443']],
            'a base URL over http' => [fn () => ['app_base_url' => 'http://tareas.segurex.com.co']],
        ];
    }

    #[DataProvider('integrationsNotReady')]
    public function test_an_integration_that_cannot_sign_or_has_no_app_domain_is_never_called(\Closure $change): void
    {
        $item = $this->task();
        $this->integration->forceFill($change($this))->save();

        $this->assertFalse($item->fresh()->canBeCompletedFromPortal());
        $this->complete($item)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_base_url_with_a_path_and_the_explicit_https_port_is_used_as_is(): void
    {
        $this->integration->forceFill(['app_base_url' => 'https://TAREAS.segurex.com.co:443/portal'])->save();
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'completed']));

        $this->assertTrue($item->fresh()->canBeCompletedFromPortal());
        $this->complete($item)->assertSessionHas('work_status', 'Tarea completada.');

        // The HTTP client sends the host in lowercase and without the default
        // port; the signed path is the same.
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://tareas.segurex.com.co/portal/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete'
            && $request->header('X-Portal-Signature') === ['sha256='.hash_hmac('sha256', now()->getTimestamp()."\nPOST\n/portal/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete\n".$request->body(), $this->secret)]);
    }

    public function test_only_the_active_account_s_own_pending_items_can_be_completed(): void
    {
        $derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $linked = User::factory()->microsoft($derivados)->create();
        $person = Person::query()->create();
        $this->user->forceFill(['person_id' => $person->id])->save();
        $linked->forceFill(['person_id' => $person->id])->save();

        $items = [
            'someone else' => $this->task(['external_id' => 'X-1', 'assignee_oid' => (string) Str::uuid()]),
            'the linked account' => $this->task(['external_id' => 'X-2', 'assignee_tenant_id' => $derivados->tenant_id, 'assignee_oid' => $linked->azure_oid]),
            'done' => $this->task(['external_id' => 'X-3', 'status' => WorkItem::STATUS_DONE]),
            'cancelled' => $this->task(['external_id' => 'X-4', 'status' => WorkItem::STATUS_CANCELLED]),
            'deleted' => tap($this->task(['external_id' => 'X-5']))->delete(),
        ];

        foreach ($items as $case => $item) {
            $this->complete($item)->assertNotFound();
        }

        $this->actingAs($this->user)->post('/tareas/999999/completar')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_items_of_a_revoked_integration_or_without_work_write_are_not_found(): void
    {
        $item = $this->task();

        $this->integration->changeAbilities([Integration::CATALOG_READ]);
        $this->complete($item)->assertNotFound();

        $this->integration->changeAbilities([Integration::WORK_WRITE]);
        $this->integration->revoke();
        $this->complete($item)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_emergency_accounts_and_guests_cannot_complete(): void
    {
        $item = $this->task();
        $emergency = User::factory()->emergency()->create();

        $this->post(route('work-items.complete', $item))->assertRedirect(route('login'));
        $this->complete($item, $emergency)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_second_press_while_the_first_call_runs_is_not_sent(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'completed']));
        $lock = Cache::lock(TaskCompleter::lockKey($item), 10);
        $this->assertTrue($lock->get());

        $this->complete($item)->assertSessionHas('work_status', 'Ya estamos completando esta tarea. Espera un momento.');

        Http::assertNothingSent();
        $this->assertSame(WorkItem::STATUS_PENDING, $item->fresh()->status);

        $lock->release();
        $this->complete($item)->assertSessionHas('work_status', 'Tarea completada.');
    }

    public function test_the_route_is_a_throttled_post(): void
    {
        $route = Route::getRoutes()->getByName('work-items.complete');

        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('throttle:'.WorkItemCompletionController::LIMITER, $route->gatherMiddleware());

        $item = $this->task();
        $this->appAnswers(Http::response(['code' => 'not_completable'], 403));

        foreach (range(1, WorkItemCompletionController::PER_MINUTE) as $press) {
            $this->complete($item)->assertRedirect();
        }

        $this->complete($item)->assertTooManyRequests();
    }

    // --- Audit --------------------------------------------------------------------

    public function test_each_attempt_is_audited_without_the_title_the_secret_or_the_signature(): void
    {
        $item = $this->task();
        $this->appAnswers(Http::sequence()
            ->push(['code' => 'not_completable'], 403)
            ->push(['code' => 'completed'], 200));

        $this->complete($item);
        $this->complete($item);

        $failed = $this->entry('work_item.complete_failed');
        $completed = $this->entry('work_item.completed');

        foreach ([$failed, $completed] as $entry) {
            $this->assertTrue($entry->causer->is($this->user));
            $this->assertTrue($entry->subject->is($this->integration));
            $this->assertSame(self::EXTERNAL_ID, $entry->properties['attributes']['external_id']);
            $this->assertTrue(Str::isUuid($entry->properties['attributes']['request_id']));

            $stored = json_encode($entry->toArray());
            $this->assertStringNotContainsString('Enviar el acta', $stored);
            $this->assertStringNotContainsString($this->secret, $stored);
            $this->assertStringNotContainsString('sha256=', $stored);
        }

        $this->assertSame(['external_id', 'request_id', 'http_status', 'reason'], array_keys($failed->properties['attributes']));
        $this->assertSame(['external_id', 'request_id', 'http_status'], array_keys($completed->properties['attributes']));
        $this->assertSame(200, $completed->properties['attributes']['http_status']);
    }

    public function test_the_attempts_show_in_auditoria_under_api_with_who_pressed(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->microsoft($this->segurex)->create();
        $admin->assignRole('super_admin');

        $item = $this->task();
        $this->appAnswers(Http::sequence()
            ->push('', 500)
            ->push(['code' => 'completed'], 200));
        $this->complete($item);
        $this->complete($item);

        $this->actingAs($admin)->get(route('admin.audit.index', ['tipo' => 'api']))
            ->assertOk()
            ->assertSeeTextInOrder(['Jhon Castro', 'API', 'Completó una tarea de App de tareas y aprobaciones', 'Elemento', self::EXTERNAL_ID, 'Respuesta HTTP', '200'])
            ->assertSeeTextInOrder(['Jhon Castro', 'API', 'No pudo completar una tarea de App de tareas y aprobaciones', 'Elemento', self::EXTERNAL_ID, 'Respuesta HTTP', '500', 'Motivo', 'server_error'])
            ->assertDontSeeText('Enviar el acta');
    }

    // --- Review fixes -------------------------------------------------------------

    public function test_a_second_press_already_past_the_controller_check_is_not_sent(): void
    {
        $item = $this->task();
        $item->load('integration.app');
        $this->appAnswers(Http::response(['code' => 'completed']));
        $completer = app(TaskCompleter::class);

        $this->assertSame(CompletionOutcome::Completed, $completer->complete($this->user, $item));
        // The same stale copy, as a second request that passed the controller
        // before the first one finished.
        $this->assertSame(CompletionOutcome::Stale, $completer->complete($this->user, $item));

        Http::assertSentCount(1);
        $this->assertCount(1, Activity::query()->where('log_name', Integration::AUDIT_LOG)->get());
    }

    public function test_an_item_that_stopped_being_completable_inside_the_lock_is_not_sent(): void
    {
        $item = $this->task();
        $item->load('integration.app');
        WorkItem::query()->whereKey($item->id)->update(['completable_from_portal' => false]);

        $this->assertSame(CompletionOutcome::Stale, app(TaskCompleter::class)->complete($this->user, $item));

        Http::assertNothingSent();
    }

    public function test_a_higher_version_put_during_the_call_wins_over_completed(): void
    {
        $item = $this->task();
        Http::fake(function () use ($item) {
            // The app's own PUT lands while the portal waits.
            WorkItem::query()->whereKey($item->id)->update(['version' => 4, 'title' => 'Otra versión']);

            return Http::response(['code' => 'completed']);
        });

        $this->complete($item)->assertSessionHas('work_status', CompletionOutcome::Stale->message());

        $item->refresh();
        $this->assertSame(WorkItem::STATUS_PENDING, $item->status);
        $this->assertSame(4, $item->version);
        $this->assertSame(200, $this->entry('work_item.completed')->properties['attributes']['http_status']);
    }

    public function test_a_higher_version_put_during_the_call_wins_over_not_found(): void
    {
        $item = $this->task();
        Http::fake(function () use ($item) {
            WorkItem::query()->whereKey($item->id)->update(['version' => 4]);

            return Http::response(['code' => 'not_found'], 404);
        });

        $this->complete($item)->assertSessionHas('work_status', CompletionOutcome::Stale->message());

        $item->refresh();
        $this->assertFalse($item->trashed());
        $this->assertSame(4, $item->version);
        $this->assertSame('Enviar el acta de la reunión de HSE', $item->title);
    }

    public function test_an_id_too_long_for_the_column_is_not_found(): void
    {
        $this->actingAs($this->user)->post('/tareas/'.str_repeat('9', 19).'/completar')->assertNotFound();
        $this->actingAs($this->user)->post('/tareas/'.str_repeat('9', 40).'/completar')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_same_object_id_under_another_tenant_is_not_found(): void
    {
        $derivados = Company::factory()->create();
        $item = $this->task(['assignee_tenant_id' => strtolower($derivados->tenant_id)]);

        $this->complete($item)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_secret_that_cannot_be_decrypted_fails_without_calling(): void
    {
        $item = $this->task();
        // Another APP_KEY, or a damaged value.
        DB::table('integrations')->where('id', $this->integration->id)->update(['signing_secret' => 'eyJub3QiOiJ2YWxpZCJ9']);

        // Deciding whether to show the button never decrypts.
        $this->assertTrue($item->fresh()->canBeCompletedFromPortal());

        $this->complete($item)
            ->assertRedirect(route('home').'#tareas')
            ->assertSessionHas('work_status', CompletionOutcome::Failed->message());

        Http::assertNothingSent();
        $this->assertSame(WorkItem::STATUS_PENDING, $item->fresh()->status);
        $failed = $this->entry('work_item.complete_failed')->properties['attributes'];
        $this->assertNull($failed['http_status']);
        $this->assertSame('secret_unavailable', $failed['reason']);
    }
}
