<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use App\View\Home\WorkList;
use App\Work\CompletionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * «Tareas pendientes» in «Mi día» (odd/tasks/inicio-bloques.md H3,
 * docs/contrato-tareas-aprobaciones.md §4 to §7): the active account's own
 * approvals and tasks, «Ver tarea» / «Ver aprobación» and «Completar».
 */
class HomeWorkItemsTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private const TENANT = '6f1c2a9e-0000-4000-8000-000000000001';

    private Company $company;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->forTenant(self::TENANT)->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Jhon Castro']);
        $this->openableApp();

        $tareas = App::factory()->create(['slug' => 'tareas', 'name' => 'Tareas y aprobaciones', 'url' => 'https://tareas.segurex.com.co']);
        $this->integration = Integration::factory()->create([
            'name' => 'App de tareas y aprobaciones',
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $tareas->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
        $this->integration->issueSigningSecret();
    }

    public function test_there_is_no_task_block_without_pending_items(): void
    {
        $this->item(['status' => WorkItem::STATUS_DONE]);

        $response = $this->home();

        $this->assertNoElement($response, 'id', 'tareas');
        $this->assertNotContains('#tareas', $this->sectionLinks($response));
    }

    public function test_approvals_come_first_then_tasks(): void
    {
        $this->item(['title' => 'Enviar el acta de HSE']);
        $this->item(['title' => 'Aprobar la orden de compra 881', 'kind' => WorkItem::KIND_APPROVAL]);

        $response = $this->home();
        $block = $this->element($response, 'id', 'tareas');

        $this->assertStringContainsString('Tareas pendientes', $block);
        $this->assertTrue(
            strpos($block, 'Por aprobar') < strpos($block, 'Aprobar la orden de compra 881')
            && strpos($block, 'Aprobar la orden de compra 881') < strpos($block, '>Tareas<')
            && strpos($block, '>Tareas<') < strpos($block, 'Enviar el acta de HSE'),
            'Expected «Por aprobar» before «Tareas».',
        );
        $this->assertStringContainsString('Tareas y aprobaciones', $block);
        $this->assertContains('#tareas', $this->sectionLinks($response));
    }

    public function test_overdue_items_lead_and_those_without_a_date_come_last(): void
    {
        $this->item(['title' => 'Sin fecha', 'due_at' => null]);
        $this->item(['title' => 'Vence el viernes', 'due_at' => CarbonImmutable::parse('2026-11-06 17:00', 'America/Bogota')]);
        $this->item(['title' => 'Vence hoy', 'due_at' => CarbonImmutable::parse('2026-11-04 17:00', 'America/Bogota')]);
        $this->item(['title' => 'Atrasada', 'due_at' => CarbonImmutable::parse('2026-11-02 12:00', 'America/Bogota')]);

        $block = $this->element($this->home(), 'id', 'tareas');

        $positions = array_map(fn (string $title) => strpos($block, $title), ['Atrasada', 'Vence hoy', 'Vence el viernes', 'Sin fecha']);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('Vencida', $block);
        $this->assertStringContainsString('Vence hoy, 17:00', $block);
        $this->assertStringContainsString('Vence el 6 nov', $block);
    }

    public function test_only_the_active_accounts_own_pending_items_show(): void
    {
        $this->item(['title' => 'Mía']);
        $this->item(['title' => 'De otra persona', 'assignee_oid' => '0b7d5e3a-0000-4000-8000-0000000000ff']);
        $this->item(['title' => 'De otro inquilino', 'assignee_tenant_id' => '6f1c2a9e-0000-4000-8000-0000000000ff']);
        $this->item(['title' => 'Cancelada', 'status' => WorkItem::STATUS_CANCELLED]);
        $revoked = Integration::factory()->create(['abilities' => [Integration::WORK_WRITE], 'app_id' => $this->integration->app_id, 'revoked_at' => now()]);
        $this->item(['title' => 'De una integración revocada', 'integration_id' => $revoked->id]);

        $block = $this->element($this->home(), 'id', 'tareas');

        $this->assertStringContainsString('Mía', $block);
        foreach (['De otra persona', 'De otro inquilino', 'Cancelada', 'De una integración revocada'] as $title) {
            $this->assertStringNotContainsString($title, $block);
        }
    }

    public function test_the_emergency_account_sees_no_tasks(): void
    {
        $this->item();
        $emergency = User::factory()->create(['is_emergency' => true, 'company_id' => $this->company->id]);

        $this->assertNoElement($this->home(as: $emergency), 'id', 'tareas');
    }

    public function test_view_links_open_the_app_in_a_new_tab(): void
    {
        $task = $this->item(['url' => 'https://tareas.segurex.com.co/tareas/12']);
        $approval = $this->item(['kind' => WorkItem::KIND_APPROVAL, 'url' => 'https://tareas.segurex.com.co/aprobaciones/7']);

        $response = $this->home();

        $taskRow = $this->element($response, 'data-work-item', (string) $task->id);
        $this->assertStringContainsString('Ver tarea', $taskRow);
        $this->assertStringContainsString('href="https://tareas.segurex.com.co/tareas/12"', $taskRow);
        $this->assertStringContainsString('target="_blank"', $taskRow);
        $this->assertStringContainsString('rel="noopener noreferrer"', $taskRow);

        $approvalRow = $this->element($response, 'data-work-item', (string) $approval->id);
        $this->assertStringContainsString('Ver aprobación', $approvalRow);
        $this->assertStringContainsString('href="https://tareas.segurex.com.co/aprobaciones/7"', $approvalRow);
        $this->assertStringNotContainsString('Completar', $approvalRow);
        $this->assertStringNotContainsString('<form', $approvalRow);
    }

    public function test_an_item_whose_url_is_not_http_shows_without_a_view_link(): void
    {
        $task = $this->item(['title' => 'Enlace raro', 'url' => 'javascript:alert(1)']);

        $row = $this->element($this->home(), 'data-work-item', (string) $task->id);

        $this->assertStringContainsString('Enlace raro', $row);
        $this->assertStringNotContainsString('Ver tarea', $row);
        $this->assertStringNotContainsString('javascript:', $row);
    }

    public function test_a_completable_task_offers_completar_as_a_post_with_csrf_and_a_confirmation(): void
    {
        $task = $this->item(['title' => 'Enviar el acta de HSE']);

        $row = $this->element($this->home(), 'data-work-item', (string) $task->id);

        $this->assertStringContainsString('action="'.route('work-items.complete', $task).'"', $row);
        $this->assertStringContainsString('method="POST"', $row);
        $this->assertStringContainsString('name="_token"', $row);
        $this->assertStringContainsString('Completar', $row);
        $this->assertStringContainsString('Sí, completar', $row);
        $this->assertStringContainsString('data-complete', $row);
    }

    public function test_completar_shows_only_when_the_portal_may_complete_the_task(): void
    {
        $notMarked = $this->item(['completable_from_portal' => false]);
        $withoutSecret = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $this->integration->app_id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
        $unsigned = $this->item(['integration_id' => $withoutSecret->id]);
        $offHost = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $this->integration->app_id,
            'app_base_url' => 'https://otro.segurex.com.co',
        ]);
        $offHost->issueSigningSecret();
        $wrongHost = $this->item(['integration_id' => $offHost->id]);

        $response = $this->home();

        foreach ([$notMarked, $unsigned, $wrongHost] as $item) {
            $row = $this->element($response, 'data-work-item', (string) $item->id);
            $this->assertStringNotContainsString('<form', $row, $item->title);
            $this->assertStringContainsString('Ver tarea', $row);
        }
    }

    public function test_each_group_shows_twenty_and_links_to_the_app_for_the_rest(): void
    {
        $this->showInCatalog($this->integration->app);
        foreach (range(1, WorkList::LIMIT + 1) as $i) {
            $this->item(['title' => "Tarea {$i}"]);
        }
        $this->item(['kind' => WorkItem::KIND_APPROVAL, 'title' => 'Única aprobación']);

        $response = $this->home();

        $tasks = $this->xpath($response)->query('//*[@id="tareas"]//*[@data-work-kind="task"]//*[@data-work-item]');
        $this->assertSame(WorkList::LIMIT, $tasks->length);
        $more = $this->element($response, 'data-work-more', 'task');
        $this->assertStringContainsString('Ver todas', $more);
        $this->assertStringContainsString('href="https://tareas.segurex.com.co"', $more);
        $this->assertNoElement($response, 'data-work-more', 'approval');
    }

    public function test_ver_todas_lists_every_visible_app_of_the_group_even_past_the_first_twenty(): void
    {
        $this->showInCatalog($this->integration->app);
        foreach (range(1, WorkList::LIMIT + 1) as $i) {
            $this->item(['title' => "Tarea {$i}", 'due_at' => now()->addDays($i)]);
        }
        $compras = $this->openableApp(['name' => 'Compras', 'url' => 'https://compras.segurex.com.co']);
        $other = Integration::factory()->create(['abilities' => [Integration::WORK_WRITE], 'app_id' => $compras->id]);
        // Undated, so it sorts last: past the twenty the block lists.
        $this->item(['title' => 'Tarea de compras', 'integration_id' => $other->id, 'due_at' => null]);

        $links = $this->xpath($this->home())->query('//*[@data-work-more="task"]');

        $this->assertSame(['https://compras.segurex.com.co', 'https://tareas.segurex.com.co'], array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links)));
    }

    public function test_ver_todas_never_links_to_an_app_the_account_cannot_see(): void
    {
        foreach (range(1, WorkList::LIMIT + 1) as $i) {
            $this->item(['title' => "Tarea {$i}"]);
        }

        $response = $this->home();

        $this->assertSame(WorkList::LIMIT, $this->xpath($response)->query('//*[@data-work-kind="task"]//*[@data-work-item]')->length);
        $this->assertNoElement($response, 'data-work-more', 'task');
    }

    public function test_the_completion_message_shows_in_the_task_block(): void
    {
        $this->item(['title' => 'Otra tarea']);

        $response = $this->withSession(['work_status' => CompletionOutcome::Completed->message()])->home();

        $message = $this->element($response, 'data-work-status');
        $this->assertStringContainsString('Tarea completada.', $message);
        $this->assertStringContainsString('role="status"', $message);
        $this->assertSame(1, $this->xpath($response)->query('//*[@data-flash]')->length);
        $this->assertSame(1, $this->xpath($response)->query('//*[@id="tareas"]//*[@data-flash]')->length, 'The only message is the one in the block.');
    }

    public function test_a_completion_text_flashed_as_an_account_message_stays_at_the_top(): void
    {
        $this->item();

        $response = $this->withSession(['status' => CompletionOutcome::Completed->message()])->home();

        $this->assertStringContainsString('Tarea completada.', $this->element($response, 'data-flash'));
        $this->assertNoElement($response, 'data-work-status');
    }

    /**
     * The service worker (resources/js/sw.js) saves the home page for
     * offline use without the messages of that one response (`FLASH`), so
     * «Tarea completada.» never comes back from the saved copy. This runs
     * the worker's own pattern on the real page.
     */
    public function test_the_saved_offline_copy_drops_the_completion_message(): void
    {
        $this->item(['title' => 'Otra tarea']);
        $worker = $this->get('/sw.js')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~^const FLASH = /(.+)/g;$~m', $worker, $match), 'The worker defines FLASH.');

        $html = $this->withSession(['work_status' => CompletionOutcome::Completed->message()])->home()->getContent();
        $saved = (string) preg_replace('/'.$match[1].'/', '', $html);

        $this->assertStringContainsString('Tarea completada.', $html);
        $this->assertStringNotContainsString('Tarea completada.', $saved);
        $this->assertStringNotContainsString('data-flash', $saved);
        $this->assertStringContainsString('Otra tarea', $saved, 'Only the message goes.');
    }

    public function test_the_block_stays_with_its_message_after_the_last_task(): void
    {
        $response = $this->withSession(['work_status' => CompletionOutcome::Completed->message()])->home();
        $block = $this->element($response, 'id', 'tareas');

        $this->assertStringContainsString('Tarea completada.', $block);
        $this->assertStringContainsString('No tienes tareas ni aprobaciones pendientes.', $block);
    }

    public function test_other_messages_stay_at_the_top(): void
    {
        $this->item();

        $response = $this->withSession(['status' => 'Vinculaste tu cuenta de Derivados.'])->home();

        $this->assertStringContainsString('Vinculaste tu cuenta de Derivados.', $this->element($response, 'data-flash'));
        $this->assertStringNotContainsString('Vinculaste', $this->element($response, 'id', 'tareas'));
    }

    /**
     * Makes an existing app one the user sees in the catalog.
     */
    private function showInCatalog(App $app): void
    {
        $app->companies()->attach($this->company->id);
        AppAccessRule::forCompany($app, $this->company);
    }

    /**
     * A pending task of the user's own Microsoft account.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): WorkItem
    {
        return WorkItem::factory()->create([
            'integration_id' => $this->integration->id,
            'assignee_tenant_id' => self::TENANT,
            'assignee_oid' => strtolower((string) $this->user->azure_oid),
            ...$attributes,
        ]);
    }
}
