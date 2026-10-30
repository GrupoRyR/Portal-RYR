<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use phpseclib4\Crypt\RSA;

/**
 * The OAuth dance Claude does with the portal, step by step: register a
 * client, open the consent screen, approve, exchange the code (PKCE S256)
 * and call the MCP server with the bearer token. Real Passport tokens,
 * signed with a key pair made once per test process (no key files).
 */
trait InteractsWithMcp
{
    public const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    private string $verifier = '';

    protected function enableMcp(): void
    {
        self::$passportKeys ??= (function (): array {
            $key = RSA::createKey(2048);

            return ['private' => (string) $key, 'public' => (string) $key->getPublicKey()];
        })();

        config([
            'portal.mcp.enabled' => true,
            'passport.private_key' => self::$passportKeys['private'],
            'passport.public_key' => self::$passportKeys['public'],
        ]);
    }

    protected function eligibleUser(array $attributes = [], ?Company $company = null): User
    {
        $company ??= Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);

        return User::factory()->microsoft($company)->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
            ...$attributes,
        ]);
    }

    protected function registerClient(array $redirectUris = [self::CALLBACK], string $name = 'Claude'): TestResponse
    {
        return $this->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
        ]);
    }

    protected function registeredClientId(): string
    {
        return (string) $this->registerClient()->assertCreated()->json('client_id');
    }

    protected function authorizeUrl(string $clientId): string
    {
        $this->verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');

        return '/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Shows the consent screen to the user and approves it: the redirect
     * back to Claude, with the code.
     */
    protected function approve(User $user, string $clientId): TestResponse
    {
        $this->actingAs($user)->get($this->authorizeUrl($clientId))->assertOk();

        return $this->actingAs($user)->post('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ]);
    }

    /**
     * The whole connection: register, approve, exchange the code.
     *
     * @return array{client_id: string, access_token: string, refresh_token: string}
     */
    protected function connect(User $user): array
    {
        $clientId = $this->registeredClientId();
        $redirect = $this->approve($user, $clientId)->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CALLBACK.'?', (string) $redirect);
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

        $this->app['auth']->forgetGuards();

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::CALLBACK,
            'code_verifier' => $this->verifier,
            'code' => $query['code'] ?? '',
        ])->assertOk();

        return [
            'client_id' => $clientId,
            'access_token' => (string) $tokens->json('access_token'),
            'refresh_token' => (string) $tokens->json('refresh_token'),
        ];
    }

    /**
     * One JSON-RPC call to the portal's MCP server.
     *
     * @param  array<string, mixed>  $params
     */
    protected function mcp(?string $token, string $method, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $this->postJson('/mcp/portal', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], $headers);
    }
}
