<?php

namespace Tests\Feature\Mcp;

use App\Audit\AuditPresenter;
use App\Models\Company;
use App\Models\Integration;
use App\Models\McpConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * After the consent: the connection record, the token Claude gets and
 * what the portal checks on every MCP call.
 */
class ConnectionTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    public function test_approving_records_the_connection_with_the_approving_account(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 0));
        $user = $this->eligibleUser();

        $tokens = $this->connect($user);

        $connection = McpConnection::query()->sole();
        $this->assertSame($tokens['client_id'], $connection->oauth_client_id);
        $this->assertTrue($connection->user->is($user));
        $this->assertNull($connection->revoked_at);
        $this->assertNull($connection->last_used_at);
        $this->assertSame([$user->id], $connection->accounts()->pluck('user_id')->all());
        $this->assertNotNull($connection->accounts()->sole()->confirmed_at);
    }

    public function test_approving_again_keeps_one_connection(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();

        $this->approve($user, $clientId)->assertRedirect();
        $this->approve($user, $clientId)->assertRedirect();

        $this->assertSame(1, McpConnection::query()->count());
        $this->assertSame(1, DB::table('mcp_connection_accounts')->count());
    }

    public function test_the_connection_is_audited_with_the_client_and_the_account(): void
    {
        $user = $this->eligibleUser();
        $this->connect($user);

        $activity = Activity::query()->where('event', 'mcp.connected')->sole();
        $this->assertTrue($activity->causer->is($user));
        $this->assertSame('Claude', $activity->properties->get('client'));
        $this->assertSame('jhon.castro@segurex.com', $activity->properties->get('email'));

        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertSame('Conectó Claude («Claude») con su cuenta de Segurex Latam S.A.S. (jhon.castro@segurex.com)', $entry->what);
    }

    public function test_the_token_lasts_one_hour(): void
    {
        $this->connect($this->eligibleUser());

        // league/oauth2-server dates tokens with the real clock, so no
        // travelTo() here.
        $expiresAt = CarbonImmutable::parse(DB::table('oauth_access_tokens')->value('expires_at'));
        $this->assertTrue($expiresAt->between(CarbonImmutable::now()->addMinutes(59), CarbonImmutable::now()->addMinutes(61)));
    }

    public function test_the_token_calls_the_server_and_quien_soy_answers_with_the_account(): void
    {
        $user = $this->eligibleUser();
        $tokens = $this->connect($user);

        $this->mcp($tokens['access_token'], 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'claude-ai', 'version' => '1.0'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'Portal RYR');

        $this->mcp($tokens['access_token'], 'tools/list')
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'quien_soy')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
            ->assertJsonCount(1, 'result.tools');

        $call = $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'quien_soy', 'arguments' => []])
            ->assertOk();

        $this->assertSame(['cuentas' => [[
            'nombre' => 'Jhon Castro',
            'correo' => 'jhon.castro@segurex.com',
            'empresa' => 'Segurex Latam S.A.S.',
        ]]], $call->json('result.structuredContent'));
    }

    public function test_last_used_is_written_at_most_once_a_minute(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 0, 0));
        $tokens = $this->connect($this->eligibleUser());

        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertSame('2026-10-30 09:00:00', (string) McpConnection::query()->sole()->last_used_at);

        $this->travelTo(now()->addSeconds(59));
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertSame('2026-10-30 09:00:00', (string) McpConnection::query()->sole()->last_used_at);

        $this->travelTo(now()->addSeconds(2));
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        $this->assertSame('2026-10-30 09:01:01', (string) McpConnection::query()->sole()->last_used_at);
    }

    public function test_a_revoked_connection_no_longer_works(): void
    {
        $tokens = $this->connect($this->eligibleUser());
        $this->mcp($tokens['access_token'], 'tools/list')->assertOk();

        McpConnection::query()->sole()->revoke();

        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ])->assertStatus(400);
    }

    public function test_a_code_approved_before_the_revocation_cannot_be_exchanged(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();
        $code = $this->approvedCode($user, $clientId);

        McpConnection::query()->sole()->revoke();

        $this->exchange($clientId, $code)->assertStatus(400);
    }

    public function test_a_code_from_a_revoked_connection_does_not_work_after_approving_again(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();
        $old = $this->approvedCode($user, $clientId);
        McpConnection::query()->sole()->revoke();

        $this->connect($user, $clientId);

        $this->exchange($clientId, $old)->assertStatus(400);
    }

    public function test_tokens_of_an_ended_connection_do_not_work_on_the_new_one(): void
    {
        $user = $this->eligibleUser();
        $old = $this->connect($user);
        // Ended without its tokens being revoked (e.g. by hand).
        McpConnection::query()->sole()->forceFill(['revoked_at' => now(), 'active_key' => null])->save();

        $new = $this->connect($user, $old['client_id']);

        $this->assertSame(2, McpConnection::query()->count());
        $this->mcp($old['access_token'], 'tools/list')->assertUnauthorized();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $old['client_id'],
            'refresh_token' => $old['refresh_token'],
        ])->assertStatus(400);
        $this->mcp($new['access_token'], 'tools/list')->assertOk();
    }

    public function test_the_database_allows_one_active_connection_per_client_and_account(): void
    {
        $user = $this->eligibleUser();
        $this->connect($user);
        $connection = McpConnection::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        McpConnection::query()->create(['oauth_client_id' => $connection->oauth_client_id, 'user_id' => $user->id]);
    }

    public function test_a_revoked_connection_leaves_room_for_a_new_one(): void
    {
        $user = $this->eligibleUser();
        $tokens = $this->connect($user);
        McpConnection::query()->sole()->revoke();

        $this->connect($user, $tokens['client_id']);

        $this->assertSame(1, McpConnection::query()->active()->count());
        $this->assertSame(2, McpConnection::query()->count());
    }

    public function test_bad_tokens_are_rate_limited_per_address_before_authentication(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->mcp('not-a-token', 'tools/list')->assertUnauthorized();
        }

        $this->mcp('not-a-token', 'tools/list')->assertTooManyRequests();
    }

    public function test_good_calls_do_not_use_the_bad_token_budget(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        for ($i = 0; $i < 31; $i++) {
            $this->mcp($tokens['access_token'], 'tools/list')->assertOk();
        }
    }

    public function test_a_token_without_a_connection_is_refused(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        // E.g. a token issued before the connection was deleted.
        McpConnection::query()->delete();

        $this->mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
    }

    public function test_an_approving_account_that_can_no_longer_connect_is_refused(): void
    {
        $user = $this->eligibleUser();
        $tokens = $this->connect($user);

        $user->forceFill(['is_guest' => true])->save();
        $this->mcp($tokens['access_token'], 'tools/list')->assertForbidden();

        $user->forceFill(['is_guest' => false])->save();
        $user->company->update(['is_active' => false]);
        $this->mcp($tokens['access_token'], 'tools/list')->assertForbidden();
    }

    public function test_the_refresh_token_rotates(): void
    {
        $tokens = $this->connect($this->eligibleUser());
        $refresh = fn () => $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ]);

        $fresh = $refresh()->assertOk();
        $this->assertNotSame($tokens['refresh_token'], $fresh->json('refresh_token'));
        $this->mcp($fresh->json('access_token'), 'tools/list')->assertOk();

        // The old refresh token was used once: it is spent.
        $refresh()->assertStatus(400);
    }

    public function test_a_passport_token_cannot_call_the_api_and_an_integration_token_cannot_call_mcp(): void
    {
        $tokens = $this->connect($this->eligibleUser());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/apps', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertUnauthorized();

        $integration = Integration::factory()->create(['abilities' => [Integration::CATALOG_READ]])->issueToken();
        $this->mcp($integration, 'tools/list')->assertUnauthorized();
    }

    public function test_the_connection_and_its_accounts_go_with_the_account(): void
    {
        $user = $this->eligibleUser();
        $this->connect($user);

        $user->delete();

        $this->assertSame(0, McpConnection::query()->count());
        $this->assertSame(0, DB::table('mcp_connection_accounts')->count());
        $this->assertInstanceOf(Company::class, Company::query()->first());
    }
}
