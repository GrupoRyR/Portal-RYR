<?php

namespace Tests\Feature\Api;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class IntegrationTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A bare authenticated endpoint, so token handling is tested apart
        // from what each real endpoint does.
        Route::middleware(['api', 'auth:sanctum', 'integration', 'throttle:api'])
            ->get('/api/v1/_probe', fn () => response()->json(['name' => request()->user()->name]));
    }

    private function integration(array $attributes = []): Integration
    {
        return Integration::factory()->create([
            'name' => 'Monitor de disponibilidad',
            'abilities' => [Integration::CATALOG_READ, Integration::STATUS_WRITE],
            ...$attributes,
        ]);
    }

    private function probe(?string $token, array $server = [])
    {
        $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

        // Each real request starts with no authenticated caller.
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables($server)->getJson('/api/v1/_probe', $headers);
    }

    // --- Issuing ---------------------------------------------------------------

    public function test_a_token_is_recognisable_and_only_its_hash_is_stored(): void
    {
        $integration = $this->integration();
        $plain = $integration->issueToken();

        $this->assertMatchesRegularExpression('/^ryr_[A-Za-z0-9]{40}[0-9a-f]{8}$/', $plain);
        $this->assertStringNotContainsString('|', $plain);
        $this->assertSame(substr($plain, -4), $integration->fresh()->token_hint);
        $this->assertSame('ryr_…'.substr($plain, -4), $integration->fresh()->maskedToken());

        $row = DB::table('personal_access_tokens')->sole();
        $this->assertSame(hash('sha256', $plain), $row->token);
        $this->assertSame([Integration::CATALOG_READ, Integration::STATUS_WRITE], json_decode($row->abilities, true));
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);
    }

    public function test_only_known_abilities_are_kept(): void
    {
        $integration = $this->integration(['abilities' => ['status:write', '*', 'users:read', 'status:write']]);

        $this->assertSame([Integration::STATUS_WRITE], $integration->abilities);
    }

    public function test_a_valid_token_authenticates_its_integration_and_records_its_use(): void
    {
        $integration = $this->integration();
        $plain = $integration->issueToken();
        $this->assertNull($integration->fresh()->last_used_at);

        $this->probe($plain)->assertOk()->assertExactJson(['name' => 'Monitor de disponibilidad']);

        $this->assertNotNull($integration->fresh()->last_used_at);
    }

    public function test_the_last_use_is_written_at_most_once_a_minute(): void
    {
        $integration = $this->integration();
        $plain = $integration->issueToken();

        $this->travelTo(now()->setTime(10, 0, 0));
        $this->probe($plain)->assertOk();
        $this->travelTo(now()->setTime(10, 0, 30));
        $this->probe($plain)->assertOk();
        $this->assertSame('10:00:00', $integration->fresh()->last_used_at->format('H:i:s'));

        $this->travelTo(now()->setTime(10, 1, 30));
        $this->probe($plain)->assertOk();
        $this->assertSame('10:01:30', $integration->fresh()->last_used_at->format('H:i:s'));
    }

    // --- Rejections --------------------------------------------------------------

    public function test_missing_and_unknown_tokens_get_a_json_401_even_without_accept_header(): void
    {
        $this->get('/api/v1/_probe')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->get('/api/v1/_probe', ['Authorization' => 'Bearer ryr_nope'])->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_rotated_away_token_stops_working_at_once(): void
    {
        $integration = $this->integration();
        $old = $integration->issueToken();
        $new = $integration->rotate();

        $this->assertNotSame($old, $new);
        $this->probe($old)->assertUnauthorized();
        $this->probe($new)->assertOk();
        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->assertSame([Integration::CATALOG_READ, Integration::STATUS_WRITE], $integration->fresh()->token()->first()->abilities);
    }

    public function test_a_revoked_token_gets_401_and_cannot_be_rotated(): void
    {
        $integration = $this->integration();
        $plain = $integration->issueToken();

        $integration->revoke();

        $this->assertTrue($integration->fresh()->isRevoked());
        $this->probe($plain)->assertUnauthorized();
        $this->assertSame(0, PersonalAccessToken::query()->count());

        $this->expectException(\LogicException::class);
        $integration->fresh()->rotate();
    }

    public function test_a_token_used_from_an_ip_outside_its_list_gets_403(): void
    {
        $integration = $this->integration(['allowed_ips' => ['10.0.0.5', '192.168.1.0/24']]);
        $plain = $integration->issueToken();

        $this->probe($plain, ['REMOTE_ADDR' => '10.0.0.5'])->assertOk();
        $this->probe($plain, ['REMOTE_ADDR' => '192.168.1.77'])->assertOk();
        $this->probe($plain, ['REMOTE_ADDR' => '10.0.0.6'])
            ->assertForbidden()
            ->assertJson(['message' => 'This IP address is not allowed for this token.']);
    }

    public function test_an_empty_ip_list_accepts_any_address(): void
    {
        $plain = $this->integration(['allowed_ips' => []])->issueToken();

        $this->probe($plain, ['REMOTE_ADDR' => '203.0.113.9'])->assertOk();
    }

    public function test_ip_entries_are_validated(): void
    {
        $this->assertTrue(Integration::isValidIpEntry('10.0.0.5'));
        $this->assertTrue(Integration::isValidIpEntry('2001:db8::1'));
        $this->assertTrue(Integration::isValidIpEntry('192.168.1.0/24'));
        $this->assertFalse(Integration::isValidIpEntry('192.168.1.0/33'));
        $this->assertFalse(Integration::isValidIpEntry('servidor.local'));
        $this->assertFalse(Integration::isValidIpEntry(''));
    }

    public function test_requests_are_rate_limited_per_token(): void
    {
        $plain = $this->integration()->issueToken();
        $other = $this->integration(['name' => 'Otro sistema'])->issueToken();

        for ($i = 0; $i < Integration::REQUESTS_PER_MINUTE; $i++) {
            $this->probe($plain)->assertOk();
        }

        $this->probe($plain)->assertTooManyRequests()->assertJsonStructure(['message']);
        $this->probe($other)->assertOk();
    }

    public function test_unknown_api_routes_answer_json_404(): void
    {
        $this->get('/api/v1/nada')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
}
