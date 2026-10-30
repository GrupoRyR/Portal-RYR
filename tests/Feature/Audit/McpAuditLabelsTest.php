<?php

namespace Tests\Feature\Audit;

use App\Livewire\Admin\McpConnector;
use App\Mcp\McpSwitch;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Mcp\InteractsWithMcp;
use Tests\TestCase;

/**
 * The connector's audit lines in Auditoría: Spanish labels, under
 * «Seguridad», findable by the person, and in the CSV export.
 */
class McpAuditLabelsTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private User $admin;

    private User $approver;

    private User $linked;

    private int $connectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:20', 'America/Bogota'));
        $this->enableMcp();

        $segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex', 'slug' => 'segurex']);
        $derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'slug' => 'derivados']);
        $this->admin = User::factory()->microsoft($segurex)->create(['name' => 'Ana Admin', 'email' => 'ana.admin@segurex.com']);
        $this->admin->assignRole('super_admin');
        $this->approver = $this->eligibleUser([], $segurex);
        $this->linked = User::factory()->microsoft($derivados)->create(['name' => 'Jhon C.', 'email' => 'derivados.info@derivadoscol.com']);
    }

    /**
     * One of each line: a tool call, a disconnection by the included
     * account, a revocation by the admin and the switch off and on.
     */
    private function writeEveryLine(): void
    {
        $tokens = $this->connect($this->approver);
        $connection = McpConnection::query()->sole();
        $connection->accounts()->create(['user_id' => $this->linked->id, 'confirmed_at' => now()]);
        $this->connectionId = $connection->id;

        $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->linked, 'web')->post(route('account.claude.disconnect', $connection))->assertRedirect();

        $this->connect($this->approver);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        Livewire::actingAs($this->admin)->test(McpConnector::class)
            ->call('revoke', McpConnection::query()->active()->sole()->id)
            ->call('toggle')
            ->call('toggle');
    }

    public function test_every_connector_line_has_a_spanish_label_in_the_audit_page(): void
    {
        $this->writeEveryLine();

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['tipo' => 'seguridad']))
            ->assertOk()
            ->assertSeeText('Encendió el conector de Claude')
            ->assertSeeText('Apagó el conector de Claude')
            ->assertSeeText('Revocó la conexión de Claude («Claude») · cuentas: Segurex Latam S.A.S. (jhon.castro@segurex.com)')
            ->assertSeeText('Desconectó Claude («Claude») · cuentas: Segurex Latam S.A.S. (jhon.castro@segurex.com), Soluciones y Derivados (derivados.info@derivadoscol.com)')
            ->assertSeeText('Claude usó la herramienta quien_soy · conexión #'.$this->connectionId)
            ->assertDontSeeText('mcp.tool_called')
            ->assertDontSeeText('mcp.disconnected')
            ->assertDontSeeText('mcp.revoked_by_admin')
            ->assertDontSeeText('mcp.switched');
    }

    public function test_the_lines_are_security_and_not_changes(): void
    {
        $this->writeEveryLine();

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['tipo' => 'cambios']))
            ->assertOk()
            ->assertDontSeeText('Claude usó la herramienta')
            ->assertDontSeeText('Desconectó Claude')
            ->assertDontSeeText('conector de Claude');
    }

    public function test_the_search_finds_the_lines_by_the_person(): void
    {
        $this->writeEveryLine();

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => 'derivados.info']))
            ->assertOk()
            ->assertSeeText('Desconectó Claude')
            ->assertDontSeeText('Claude usó la herramienta');
    }

    public function test_the_switch_notes_when_the_deploy_flag_is_off(): void
    {
        config(['portal.mcp.enabled' => false]);
        app(McpSwitch::class)->turn(false, $this->admin);

        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertSeeText('Apagó el conector de Claude · PORTAL_MCP_ENABLED está apagado en el servidor');
    }

    public function test_the_csv_export_carries_the_labels(): void
    {
        $this->writeEveryLine();

        $csv = $this->actingAs($this->admin)->get(route('admin.audit.export', ['tipo' => 'seguridad']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Claude usó la herramienta quien_soy · conexión #'.$this->connectionId, $csv);
        $this->assertStringContainsString('Desconectó Claude («Claude»)', $csv);
        $this->assertStringContainsString('Revocó la conexión de Claude («Claude»)', $csv);
        $this->assertStringContainsString('Apagó el conector de Claude', $csv);
        $this->assertStringContainsString('Encendió el conector de Claude', $csv);
    }
}
