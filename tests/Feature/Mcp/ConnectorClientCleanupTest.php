<?php

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * `portal:purge-mcp-clients`: registration is public and passport:purge
 * never deletes clients, so connector clients that never became a
 * connection are deleted after a day, with their codes and tokens.
 */
class ConnectorClientCleanupTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    private function purge(): void
    {
        $this->artisan('portal:purge-mcp-clients')->assertSuccessful();
    }

    public function test_a_day_old_client_without_a_connection_is_deleted_with_its_codes_and_tokens(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();
        // Approved, so it has a code and a token, then its connection was
        // deleted: nothing points at the client any more.
        $this->connect($user, $clientId);
        $this->approvedCode($user, $clientId);
        McpConnection::query()->delete();

        $this->travel(25)->hours();
        $this->purge();

        $this->assertSame(0, DB::table('oauth_clients')->count());
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->count());
    }

    public function test_a_young_client_is_kept(): void
    {
        $this->registeredClientId();

        $this->travel(23)->hours();
        $this->purge();

        $this->assertSame(1, DB::table('oauth_clients')->count());
    }

    public function test_a_client_with_a_connection_is_kept_even_when_revoked(): void
    {
        $this->connect($this->eligibleUser());
        McpConnection::query()->sole()->revoke();

        $this->travel(30)->days();
        $this->purge();

        $this->assertSame(1, DB::table('oauth_clients')->count());
        $this->assertSame(1, McpConnection::query()->count());
    }

    public function test_a_client_that_is_not_the_connectors_is_kept(): void
    {
        $client = Passport::client()->newQuery()->forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Otra integración',
            'redirect_uris' => ['https://example.com/callback'],
            'grant_types' => ['authorization_code'],
            'revoked' => false,
        ]);

        $this->travel(2)->days();
        $this->purge();

        $this->assertTrue(DB::table('oauth_clients')->where('id', $client->getKey())->exists());
    }
}
