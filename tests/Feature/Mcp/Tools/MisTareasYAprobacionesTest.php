<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `mis_tareas` and `mis_aprobaciones`: the pending work items each account
 * of the connection sees in its own home (WorkItem::scopeVisibleTo(), by
 * `{tenant_id, oid}`, never `person_id`), labelled with the account's
 * company, soonest due first and those without a date last.
 */
class MisTareasYAprobacionesTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();

        $app = App::factory()->create(['slug' => 'tareas', 'url' => 'https://tareas.segurex.com.co']);
        $this->integration = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $app->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
    }

    private function item(User $for, array $attributes = []): WorkItem
    {
        return WorkItem::factory()->for($this->integration)->create([
            'assignee_tenant_id' => strtolower((string) $for->tenant_id),
            'assignee_oid' => strtolower((string) $for->azure_oid),
            ...$attributes,
        ]);
    }

    private function task(User $for, array $attributes = []): WorkItem
    {
        return $this->item($for, ['kind' => WorkItem::KIND_TASK, ...$attributes]);
    }

    private function approval(User $for, array $attributes = []): WorkItem
    {
        return $this->item($for, ['kind' => WorkItem::KIND_APPROVAL, 'completable_from_portal' => false, ...$attributes]);
    }

    public function test_both_accounts_tasks_come_labelled_soonest_due_first_and_undated_last(): void
    {
        $this->task($this->approver, ['title' => 'Sin fecha', 'due_at' => null, 'url' => 'https://tareas.segurex.com.co/tareas/3']);
        $this->task($this->approver, ['title' => 'Enviar el acta', 'due_at' => now()->addDay()->setTime(17, 0), 'url' => 'https://tareas.segurex.com.co/tareas/1']);
        $this->task($this->linked, ['title' => 'Revisar inventario', 'due_at' => now()->subDay(), 'url' => 'https://tareas.segurex.com.co/tareas/2']);
        $this->approval($this->approver, ['title' => 'Una aprobación']);

        $this->assertSame([
            'tareas' => [
                [
                    'titulo' => 'Revisar inventario',
                    'empresa' => 'Derivados',
                    'vence' => '2026-11-03T09:00:00-05:00',
                    'vence_texto' => 'Martes 3 de noviembre de 2026, 09:00',
                    'vencida' => true,
                    'url' => 'https://tareas.segurex.com.co/tareas/2',
                ],
                [
                    'titulo' => 'Enviar el acta',
                    'empresa' => 'Segurex',
                    'vence' => '2026-11-05T17:00:00-05:00',
                    'vence_texto' => 'Jueves 5 de noviembre de 2026, 17:00',
                    'vencida' => false,
                    'url' => 'https://tareas.segurex.com.co/tareas/1',
                ],
                [
                    'titulo' => 'Sin fecha',
                    'empresa' => 'Segurex',
                    'vence' => null,
                    'vence_texto' => null,
                    'vencida' => false,
                    'url' => 'https://tareas.segurex.com.co/tareas/3',
                ],
            ],
            'limite' => 20,
        ], $this->toolResult('mis_tareas'));
    }

    public function test_approvals_come_apart_from_tasks(): void
    {
        $this->task($this->approver, ['title' => 'Una tarea']);
        $this->approval($this->linked, ['title' => 'Compra de EPP', 'due_at' => null, 'url' => 'https://tareas.segurex.com.co/aprobaciones/431']);

        $this->assertSame([
            'aprobaciones' => [
                [
                    'titulo' => 'Compra de EPP',
                    'empresa' => 'Derivados',
                    'vence' => null,
                    'vence_texto' => null,
                    'vencida' => false,
                    'url' => 'https://tareas.segurex.com.co/aprobaciones/431',
                ],
            ],
            'limite' => 20,
        ], $this->toolResult('mis_aprobaciones'));
    }

    public function test_only_pending_items_of_the_connection_s_own_accounts_appear(): void
    {
        $shown = $this->task($this->approver, ['title' => 'Pendiente']);
        $this->task($this->approver, ['title' => 'Hecha', 'status' => WorkItem::STATUS_DONE]);
        $this->task($this->approver, ['title' => 'Cancelada', 'status' => WorkItem::STATUS_CANCELLED]);
        tap($this->task($this->approver, ['title' => 'Borrada']))->delete();
        $this->task($this->outsider(), ['title' => 'De Otra']);
        // Same tenant, another person; and the approver's object ID in another tenant.
        $this->task($this->approver, ['title' => 'De otra persona', 'assignee_oid' => (string) Str::uuid()]);
        $this->task($this->approver, ['title' => 'Otro inquilino', 'assignee_tenant_id' => strtolower((string) $this->other->tenant_id)]);

        $this->assertSame(['Pendiente'], array_column($this->toolResult('mis_tareas')['tareas'], 'titulo'));
        $this->assertSame($shown->url, $this->toolResult('mis_tareas')['tareas'][0]['url']);
    }

    public function test_items_of_an_integration_that_lost_work_write_or_was_revoked_are_left_out(): void
    {
        $this->task($this->approver);
        $this->approval($this->approver);

        $this->integration->changeAbilities([Integration::CATALOG_READ]);
        $this->assertSame([], $this->toolResult('mis_tareas')['tareas']);
        $this->assertSame([], $this->toolResult('mis_aprobaciones')['aprobaciones']);

        $this->integration->changeAbilities([Integration::WORK_WRITE]);
        $this->assertCount(1, $this->toolResult('mis_tareas')['tareas']);

        $this->integration->revoke();
        $this->assertSame([], $this->toolResult('mis_tareas')['tareas']);
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        $this->task($this->linked, ['title' => 'De Derivados']);
        $this->approval($this->linked, ['title' => 'Aprobación de Derivados']);

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame([], $this->toolResult('mis_tareas')['tareas']);
        $this->assertSame([], $this->toolResult('mis_aprobaciones')['aprobaciones']);
    }

    public function test_at_most_twenty_soonest_first(): void
    {
        foreach (range(1, 15) as $day) {
            $this->task($this->approver, ['title' => "S{$day}", 'due_at' => now()->addDays($day)]);
            $this->task($this->linked, ['title' => "D{$day}", 'due_at' => now()->addDays($day)->addMinute()]);
        }

        $result = $this->toolResult('mis_tareas');

        $this->assertSame(20, $result['limite']);
        $this->assertCount(20, $result['tareas']);
        $this->assertSame(['S1', 'D1', 'S2', 'D2'], array_slice(array_column($result['tareas'], 'titulo'), 0, 4));
        $this->assertSame(['S10', 'D10'], array_slice(array_column($result['tareas'], 'titulo'), 18, 2));
    }

    public function test_nothing_but_what_the_home_shows_comes_back(): void
    {
        $this->task($this->approver);
        $this->approval($this->approver);

        foreach (['mis_tareas', 'mis_aprobaciones'] as $tool) {
            $this->assertNoKeys($this->toolResult($tool), ['external_id', 'assignee_oid', 'assignee_tenant_id', 'oid', 'tenant_id', 'version', 'person_id', 'id']);
        }
    }
}
