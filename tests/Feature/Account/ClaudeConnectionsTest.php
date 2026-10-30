<?php

namespace Tests\Feature\Account;

use App\Audit\SecurityLog;
use App\Mcp\Connections;
use App\Mcp\McpSwitch;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Mcp\InteractsWithMcp;
use Tests\TestCase;

/**
 * «Conexiones con Claude» on /cuenta: the active connections the signed-in
 * account takes part in (as the approving account or an included one),
 * and «Desconectar», which ends the whole connection. Participation is by
 * account only: being the same person never shows nor reaches one.
 */
class ClaudeConnectionsTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $approver;

    private User $linked;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:20', 'America/Bogota'));
        $this->enableMcp();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->approver = $this->eligibleUser([], $this->segurex);
        $this->linked = User::factory()->microsoft($this->derivados)->create(['name' => 'Jhon C.', 'email' => 'derivados.info@derivadoscol.com']);
    }

    /**
     * Connects the approving account and adds the linked one to the
     * connection, as a Microsoft confirmation at consent would (C2b).
     *
     * @return array{client_id: string, access_token: string, refresh_token: string}
     */
    private function connectBoth(): array
    {
        $tokens = $this->connect($this->approver);
        McpConnection::query()->sole()->accounts()->create(['user_id' => $this->linked->id, 'confirmed_at' => now()]);

        return $tokens;
    }

    /**
     * Another account's connection, recorded as the consent step would
     * (Passport keeps its first guard for the whole test, so a second
     * account cannot go through the consent screen in the same test).
     */
    private function connectionOf(User $user): McpConnection
    {
        $connection = McpConnection::query()->create(['oauth_client_id' => $this->registeredClientId(), 'user_id' => $user->id]);
        $connection->accounts()->create(['user_id' => $user->id, 'confirmed_at' => now()]);

        return $connection;
    }

    private function samePerson(User ...$accounts): void
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }
    }

    private function page(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user, 'web')->get(route('account.show'));
    }

    private function disconnect(User $user, McpConnection|int $connection): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user, 'web')->post(route('account.claude.disconnect', $connection));
    }

    private function assertTokensAreDead(array $tokens): void
    {
        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);
    }

    // --- The list -------------------------------------------------------------

    public function test_the_approving_account_sees_its_connection_with_its_accounts_and_dates(): void
    {
        $tokens = $this->connectBoth();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 11:05', 'America/Bogota'));
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();

        $this->page($this->approver)
            ->assertOk()
            ->assertSeeText('Conexiones con Claude')
            ->assertSeeTextInOrder([
                'Claude',
                'Cliente registrado el 30 de octubre de 2026',
                'Jhon Castro', 'jhon.castro@segurex.com', 'Segurex',
                'Jhon C.', 'derivados.info@derivadoscol.com', 'Derivados',
                'Conectada el 30 de octubre de 2026, 09:20',
                'Último uso: 30 de octubre de 2026, 11:05',
                'Desconectar',
            ])
            ->assertSee('action="'.route('account.claude.disconnect', McpConnection::query()->sole()).'"', false);
    }

    public function test_an_included_account_sees_the_connection_too(): void
    {
        $this->connectBoth();

        $this->page($this->linked)
            ->assertOk()
            ->assertSeeText('Conexiones con Claude')
            ->assertSeeText('jhon.castro@segurex.com')
            ->assertSeeText('Aún no se ha usado')
            ->assertSee('action="'.route('account.claude.disconnect', McpConnection::query()->sole()).'"', false);
    }

    public function test_without_connections_the_section_says_so(): void
    {
        $this->page($this->approver)
            ->assertOk()
            ->assertSeeText('Conexiones con Claude')
            ->assertSeeText('Esta cuenta no tiene conexiones con Claude.')
            ->assertDontSeeText('Desconectar');
    }

    public function test_only_the_accounts_own_connections_are_listed(): void
    {
        $this->connect($this->approver);
        $other = User::factory()->microsoft($this->derivados)->create(['email' => 'otra.persona@derivadoscol.com']);
        $this->connectionOf($other);

        $this->page($this->approver)
            ->assertSeeText('jhon.castro@segurex.com')
            ->assertDontSeeText('otra.persona@derivadoscol.com');
        $this->page($other)
            ->assertSeeText('otra.persona@derivadoscol.com')
            ->assertDontSeeText('jhon.castro@segurex.com');
    }

    public function test_a_linked_account_of_the_same_person_that_is_not_in_the_connection_sees_nothing(): void
    {
        $this->connect($this->approver);
        $this->samePerson($this->approver, $this->linked);

        // The approving account is listed above as a linked account, never as a connection.
        $this->page($this->linked)
            ->assertSeeText('Esta cuenta no tiene conexiones con Claude.')
            ->assertDontSee('data-claude-connection', false)
            ->assertDontSee(route('account.claude.disconnect', McpConnection::query()->sole()), false);
    }

    public function test_revoked_connections_are_not_listed(): void
    {
        $this->connect($this->approver);
        McpConnection::query()->sole()->revoke();

        $this->page($this->approver)->assertSeeText('Esta cuenta no tiene conexiones con Claude.');
    }

    public function test_the_section_is_hidden_while_the_deploy_flag_is_off(): void
    {
        $this->connect($this->approver);
        config(['portal.mcp.enabled' => false]);

        $this->page($this->approver)->assertOk()->assertDontSeeText('Conexiones con Claude')->assertDontSeeText('Desconectar');
    }

    public function test_the_section_is_hidden_while_the_admin_toggle_is_off(): void
    {
        $this->connect($this->approver);
        app(McpSwitch::class)->turn(false, User::factory()->create());

        $this->page($this->approver)->assertOk()->assertDontSeeText('Conexiones con Claude');
    }

    // --- Desconectar ----------------------------------------------------------

    public function test_the_approving_account_disconnects_and_every_token_dies(): void
    {
        $tokens = $this->connectBoth();
        $connection = McpConnection::query()->sole();

        $this->disconnect($this->approver, $connection)
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'Desconectaste Claude («Claude»). Ya no puede leer los datos de esas cuentas.');

        $this->assertNotNull($connection->fresh()->revoked_at);
        $this->assertTokensAreDead($tokens);
        $this->page($this->approver)->assertSeeText('Esta cuenta no tiene conexiones con Claude.');
    }

    public function test_an_included_account_may_disconnect_the_whole_connection(): void
    {
        $tokens = $this->connectBoth();
        $connection = McpConnection::query()->sole();

        $this->disconnect($this->linked, $connection)->assertRedirect(route('account.show'));

        $this->assertNotNull($connection->fresh()->revoked_at);
        $this->assertTokensAreDead($tokens);
        $this->page($this->approver)->assertSeeText('Esta cuenta no tiene conexiones con Claude.');
    }

    public function test_disconnecting_is_audited_with_who_the_client_and_the_accounts(): void
    {
        $this->connectBoth();
        $connection = McpConnection::query()->sole();

        $this->disconnect($this->linked, $connection);

        $entry = Activity::query()->where('event', Connections::DISCONNECTED)->sole();
        $this->assertSame(SecurityLog::LOG, $entry->log_name);
        $this->assertTrue($entry->causer->is($this->linked));
        $this->assertTrue($entry->subject->is($this->approver));
        $this->assertSame('Claude', $entry->properties['client']);
        $this->assertSame($connection->id, $entry->properties['connection_id']);
        $this->assertSame([
            ['user_id' => $this->approver->id, 'company' => 'Segurex Latam S.A.S.', 'email' => 'jhon.castro@segurex.com'],
            ['user_id' => $this->linked->id, 'company' => 'Soluciones y Derivados', 'email' => 'derivados.info@derivadoscol.com'],
        ], $entry->properties['accounts']);
    }

    public function test_a_foreign_connection_is_a_404_and_stays_active(): void
    {
        $tokens = $this->connect($this->approver);
        $connection = McpConnection::query()->sole();
        $stranger = User::factory()->microsoft($this->derivados)->create();
        $this->connectionOf($stranger);

        $this->disconnect($stranger, $connection)->assertNotFound();

        $this->assertNull($connection->fresh()->revoked_at);
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertSame(0, Activity::query()->where('event', Connections::DISCONNECTED)->count());
    }

    public function test_being_the_same_person_does_not_reach_a_connection(): void
    {
        $this->connect($this->approver);
        $this->samePerson($this->approver, $this->linked);

        $this->disconnect($this->linked, McpConnection::query()->sole())->assertNotFound();

        $this->assertNull(McpConnection::query()->sole()->revoked_at);
    }

    public function test_a_revoked_or_missing_connection_is_a_404(): void
    {
        $this->connect($this->approver);
        $connection = McpConnection::query()->sole();
        $connection->revoke();

        $this->disconnect($this->approver, $connection)->assertNotFound();
        $this->disconnect($this->approver, 999999)->assertNotFound();
        $this->assertSame(0, Activity::query()->where('event', Connections::DISCONNECTED)->count());
    }

    public function test_disconnecting_is_a_404_while_the_connector_is_off(): void
    {
        $tokens = $this->connect($this->approver);
        $connection = McpConnection::query()->sole();
        config(['portal.mcp.enabled' => false]);

        $this->disconnect($this->approver, $connection)->assertNotFound();

        $this->assertNull($connection->fresh()->revoked_at);
        config(['portal.mcp.enabled' => true]);
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
    }

    public function test_guests_cannot_disconnect(): void
    {
        $this->connect($this->approver);
        $this->app['auth']->forgetGuards();
        auth()->logout();

        $this->post(route('account.claude.disconnect', McpConnection::query()->sole()))->assertRedirect(route('login'));
        $this->assertNull(McpConnection::query()->sole()->revoked_at);
    }
}
