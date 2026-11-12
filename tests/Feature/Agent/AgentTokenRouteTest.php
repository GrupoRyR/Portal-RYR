<?php

namespace Tests\Feature\Agent;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /agente/token (connection contract §3.6 and the launcher's §5.2): the
 * launcher asks for a fresh panel token when the person clicks. Same signer
 * and eligibility as PanelToken::for(). Hosts and keys are invented.
 */
class AgentTokenRouteTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://agente.example.test';

    private const URL = '/agente/token';

    private string $key;

    private Company $segurex;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->key = str_repeat("\x0a\x0b\x0c\x0d", 8);
        $this->connect();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['slug' => 'segurex', 'domains' => ['segurex.com']]);
        $this->user = User::factory()->microsoft($this->segurex)->create(['email' => 'ana.mail@segurex.com', 'upn' => 'ana@segurex.com']);
    }

    private function connect(): void
    {
        config(['agent.origin' => self::ORIGIN, 'agent.panel_key' => base64_encode($this->key)]);
    }

    /** @return array<string, mixed> */
    private function claims(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function ask(?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->user)->postJson(self::URL);
    }

    public function test_it_answers_a_json_token_that_is_never_cached(): void
    {
        $response = $this->ask()->assertOk()->assertHeader('Content-Type', 'application/json');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['token'], array_keys($response->json()));
        $token = $response->json('token');
        [$h, $p, $s] = explode('.', $token);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', $h.'.'.$p, $this->key, true)), '+/', '-_'), '='), $s);
        $claims = $this->claims($token);
        $this->assertSame(['ana@segurex.com', 'SGX', 'empleado', 'agente-ryr', 'portal-ryr'], [$claims['sub'], $claims['empresa'], $claims['rol'], $claims['aud'], $claims['iss']]);
        $this->assertLessThanOrEqual(300, $claims['exp'] - $claims['iat']);
        $this->assertLessThanOrEqual(4096, strlen($token));
    }

    public function test_each_call_signs_a_fresh_token(): void
    {
        $first = $this->claims($this->ask()->json('token'));
        $second = $this->claims($this->ask()->json('token'));

        $this->assertNotSame($first['jti'], $second['jti']);
    }

    public function test_it_never_redirects_a_signed_in_account(): void
    {
        $this->assertSame(200, $this->ask()->getStatusCode());
        // Even without «Accept: application/json».
        $this->assertSame(200, $this->actingAs($this->user)->post(self::URL)->getStatusCode());
    }

    public function test_a_guest_gets_no_token(): void
    {
        $this->postJson(self::URL)->assertUnauthorized()->assertJsonMissingPath('token');
        $this->post(self::URL)->assertRedirect(route('login'));
    }

    public function test_the_token_is_not_sent_without_the_csrf_token(): void
    {
        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->ask()->assertStatus(419)->assertJsonMissingPath('token');
    }

    public function test_other_methods_are_not_allowed(): void
    {
        foreach (['getJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($this->user)->{$method}(self::URL)->assertStatus(405);
        }
    }

    public function test_an_account_the_agent_does_not_accept_gets_no_token_and_no_detail(): void
    {
        $other = Company::factory()->create(['slug' => 'tercera', 'domains' => ['tercera.com']]);
        $accounts = [
            User::factory()->microsoft($this->segurex)->create(['email' => 'g@segurex.com', 'upn' => 'g@segurex.com', 'is_guest' => true]),
            User::factory()->microsoft($this->segurex)->create(['email' => 'n@segurex.com', 'upn' => null]),
            User::factory()->microsoft($other)->create(['email' => 't@tercera.com', 'upn' => 't@tercera.com']),
            User::factory()->microsoft($this->segurex)->create(['email' => 'f@segurex.com', 'upn' => 'f@otro.example']),
        ];

        $bodies = [];
        foreach ($accounts as $account) {
            $response = $this->ask($account)->assertNotFound()->assertJsonMissingPath('token');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $bodies[] = $response->getContent();
        }

        // The same body for every reason: nothing tells why.
        $this->assertCount(1, array_unique($bodies));
    }

    public function test_while_the_connection_is_not_configured_it_answers_the_same_not_found(): void
    {
        $rejected = $this->ask(User::factory()->microsoft($this->segurex)->create(['email' => 'n@segurex.com', 'upn' => null]))->getContent();

        foreach ([['agent.panel_key', null], ['agent.panel_key', base64_encode('short')], ['agent.origin', null], ['agent.origin', 'http://agente.example.test']] as [$name, $value]) {
            config([$name => $value]);

            $response = $this->ask()->assertNotFound();
            $this->assertSame($rejected, $response->getContent(), $name);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

            $this->connect();
        }
    }

    public function test_it_is_limited_per_person_in_a_bucket_of_its_own(): void
    {
        $other = User::factory()->microsoft($this->segurex)->create(['email' => 'luis.mail@segurex.com', 'upn' => 'luis@segurex.com']);

        foreach (range(1, 10) as $call) {
            $this->ask()->assertOk();
        }

        $this->ask()->assertStatus(429)->assertJsonMissingPath('token');
        // Another person is not affected.
        $this->ask($other)->assertOk();
    }
}
