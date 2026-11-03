<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What Claude reads before it connects: the protected-resource and
 * authorization-server metadata, and the 401 that points at them.
 */
class OAuthMetadataTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    public function test_the_protected_resource_is_exactly_the_server_url(): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp/portal')
            ->assertOk()
            ->assertJson([
                'resource' => config('app.url').'/mcp/portal',
                'authorization_servers' => [config('app.url')],
                'scopes_supported' => ['mcp:use'],
            ]);
    }

    public function test_the_authorization_server_points_at_the_portal_endpoints(): void
    {
        $this->get('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJson([
                'issuer' => config('app.url'),
                'authorization_endpoint' => config('app.url').'/oauth/authorize',
                'token_endpoint' => config('app.url').'/oauth/token',
                'registration_endpoint' => config('app.url').'/oauth/register',
                'code_challenge_methods_supported' => ['S256'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
            ]);
    }

    public function test_a_call_without_a_token_gets_401_with_the_metadata_url(): void
    {
        $response = $this->mcp(null, 'initialize')->assertUnauthorized();

        $this->assertStringContainsString(
            'resource_metadata="'.config('app.url').'/.well-known/oauth-protected-resource/mcp/portal"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_only_the_routes_the_connector_needs_exist(): void
    {
        // No personal tokens, clients API, device flow or cookie refresh.
        $this->assertFalse(Passport::$registersRoutes);
        $this->assertFalse(app('router')->has('passport.token.refresh'));
        $this->assertFalse(app('router')->has('passport.device'));
        $this->assertFalse(app('router')->has('passport.clients.index'));
        $this->assertTrue(app('router')->has('passport.token'));
        $this->assertTrue(app('router')->has('passport.authorizations.authorize'));
        $this->assertTrue(app('router')->has('passport.authorizations.approve'));
        $this->assertTrue(app('router')->has('passport.authorizations.deny'));
    }

    public function test_tokens_are_short_and_refresh_tokens_rotate(): void
    {
        $now = now();

        $this->assertEquals($now->copy()->addHour(), $now->copy()->add(Passport::tokensExpireIn()));
        $this->assertEquals($now->copy()->addDays(30), $now->copy()->add(Passport::refreshTokensExpireIn()));
        $this->assertTrue(Passport::$revokeRefreshTokenAfterUse);
    }
}
