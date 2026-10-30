<?php

namespace Tests\Feature\Admin;

use App\Audit\SecurityLog;
use App\Livewire\Admin\McpConnector;
use App\Mcp\Connections;
use App\Mcp\McpSwitch;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Mcp\InteractsWithMcp;
use Tests\TestCase;

/**
 * «Conector de Claude» in Configuración: the runtime switch of the whole
 * connector and the list of active connections, with «Revocar». Super
 * admins only; every action is re-authorized.
 */
class McpConnectorTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:20', 'America/Bogota'));
        $this->enableMcp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Admin', 'email' => 'ana.admin@segurex.com']);
        $this->admin->assignRole('super_admin');
    }

    private function connector(?User $user = null): Testable
    {
        // An MCP call earlier in the test leaves `api` as the default guard.
        $this->app['auth']->shouldUse('web');

        return Livewire::actingAs($user ?? $this->admin)->test(McpConnector::class);
    }

    private function switch(): McpSwitch
    {
        return app(McpSwitch::class);
    }

    // --- Access ---------------------------------------------------------------

    public function test_super_admins_see_the_connector_card_in_configuracion(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeText('Conector de Claude')
            ->assertSeeText('Conexiones activas');
    }

    public function test_other_roles_are_forbidden_on_every_piece(): void
    {
        $tokens = $this->connect($this->eligibleUser([], $this->segurex));
        $connection = McpConnection::query()->sole();

        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = User::factory()->microsoft($this->segurex)->create();
            $user->assignRole($role);

            $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
            $this->connector($user)->assertForbidden();
        }

        $this->connector(User::factory()->microsoft($this->segurex)->create())->assertForbidden();

        $this->assertTrue($this->switch()->turnedOn());
        $this->assertNull($connection->fresh()->revoked_at);
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
    }

    public function test_actions_are_re_authorized(): void
    {
        $this->connect($this->eligibleUser([], $this->segurex));
        $connection = McpConnection::query()->sole();
        $portalAdmin = User::factory()->microsoft($this->segurex)->create();
        $portalAdmin->assignRole('portal_admin');

        foreach ([['toggle'], ['revoke', $connection->id]] as $call) {
            $component = $this->connector();
            $this->actingAs($portalAdmin, 'web');
            $component->call(...$call)->assertForbidden();
        }

        $this->assertTrue($this->switch()->turnedOn());
        $this->assertNull($connection->fresh()->revoked_at);
    }

    // --- The switch -----------------------------------------------------------

    public function test_the_switch_shows_the_state_and_turns_the_connector_off_and_on(): void
    {
        $tokens = $this->connect($this->eligibleUser([], $this->segurex));

        $component = $this->connector()
            ->assertSet('enabled', true)
            ->assertSeeText('Encendido: Claude puede conectarse al portal.');

        $component->call('toggle')
            ->assertSet('enabled', false)
            ->assertSeeText('Apagado: el conector responde 404 y ninguna conexión funciona.');

        $this->assertFalse($this->switch()->enabled());
        $this->mcp($tokens['access_token'], 'tools/list')->assertNotFound();

        // The MCP call forgot the signed-in admin: sign in again.
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->admin, 'web');
        $component->call('toggle')->assertSet('enabled', true);

        $this->assertTrue($this->switch()->enabled());
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertSame(2, Activity::query()->where('event', McpSwitch::SWITCHED)->count());
        $this->assertTrue(Activity::query()->where('event', McpSwitch::SWITCHED)->first()->causer->is($this->admin));
    }

    public function test_the_card_warns_when_the_deploy_flag_is_off(): void
    {
        config(['portal.mcp.enabled' => false]);

        $this->connector()
            ->assertSeeText('PORTAL_MCP_ENABLED está apagado en el servidor')
            ->call('toggle')
            ->assertSet('enabled', false);

        $this->assertFalse($this->switch()->turnedOn());
    }

    // --- The list -------------------------------------------------------------

    public function test_the_list_shows_each_active_connection_with_its_accounts_and_dates(): void
    {
        $approver = $this->eligibleUser([], $this->segurex);
        $tokens = $this->connect($approver);
        $derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $linked = User::factory()->microsoft($derivados)->create(['name' => 'Jhon C.', 'email' => 'derivados.info@derivadoscol.com']);
        McpConnection::query()->sole()->accounts()->create(['user_id' => $linked->id, 'confirmed_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 11:05', 'America/Bogota'));
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();

        // Passport keeps its first guard for the whole test: a second
        // account's connection is recorded directly.
        $revoked = User::factory()->microsoft($this->segurex)->create(['email' => 'revocada@segurex.com']);
        McpConnection::query()->create(['oauth_client_id' => $tokens['client_id'], 'user_id' => $revoked->id])->revoke();

        $this->connector()
            ->assertSeeTextInOrder([
                'Claude',
                'Jhon Castro', 'jhon.castro@segurex.com',
                'Jhon C.', 'derivados.info@derivadoscol.com',
                '30 de octubre de 2026, 09:20',
                '30 de octubre de 2026, 11:05',
                'Revocar',
            ])
            ->assertDontSeeText('revocada@segurex.com');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->connector()->assertSeeText('No hay conexiones activas.')->assertDontSeeText('Revocar');
    }

    public function test_the_list_is_capped(): void
    {
        $clientId = $this->registeredClientId();
        $users = User::factory()->microsoft($this->segurex)->count(McpConnector::LIMIT + 2)->create();
        foreach ($users as $user) {
            McpConnection::query()->create(['oauth_client_id' => $clientId, 'user_id' => $user->id]);
        }

        $this->connector()
            ->assertSeeText('Se muestran las '.McpConnector::LIMIT.' más recientes de '.(McpConnector::LIMIT + 2).'.')
            ->assertSeeText($users->last()->email)
            ->assertDontSeeText($users->first()->email);
    }

    public function test_revoking_ends_the_connection_kills_its_tokens_and_is_audited(): void
    {
        $approver = $this->eligibleUser([], $this->segurex);
        $tokens = $this->connect($approver);
        $connection = McpConnection::query()->sole();

        $this->connector()
            ->call('revoke', $connection->id)
            ->assertSeeText('Se revocó la conexión de Claude («Claude») de Jhon Castro.')
            ->assertSeeText('No hay conexiones activas.');

        $this->assertNotNull($connection->fresh()->revoked_at);
        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);

        $entry = Activity::query()->where('event', Connections::REVOKED_BY_ADMIN)->sole();
        $this->assertSame(SecurityLog::LOG, $entry->log_name);
        $this->assertTrue($entry->causer->is($this->admin));
        $this->assertTrue($entry->subject->is($approver));
        $this->assertSame('Claude', $entry->properties['client']);
        $this->assertSame($connection->id, $entry->properties['connection_id']);
        $this->assertSame('jhon.castro@segurex.com', $entry->properties['accounts'][0]['email']);
    }

    public function test_revoking_a_connection_that_is_no_longer_active_changes_nothing(): void
    {
        $this->connect($this->eligibleUser([], $this->segurex));
        $connection = McpConnection::query()->sole();
        $connection->revoke();
        $revokedAt = DB::table('mcp_connections')->value('revoked_at');

        $this->connector()
            ->call('revoke', $connection->id)
            ->assertSeeText('Esa conexión ya no estaba activa.');
        $this->connector()->call('revoke', 999999)->assertSeeText('Esa conexión ya no estaba activa.');

        $this->assertSame($revokedAt, DB::table('mcp_connections')->value('revoked_at'));
        $this->assertSame(0, Activity::query()->where('event', Connections::REVOKED_BY_ADMIN)->count());
    }
}
