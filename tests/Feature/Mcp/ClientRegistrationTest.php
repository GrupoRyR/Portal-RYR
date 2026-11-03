<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dynamic client registration (POST /oauth/register) is public, so it only
 * accepts claude.ai's callback, exactly.
 */
class ClientRegistrationTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    public function test_the_claude_callback_registers_a_public_client(): void
    {
        $this->registerClient()
            ->assertCreated()
            ->assertJson([
                'redirect_uris' => [self::CALLBACK],
                'token_endpoint_auth_method' => 'none',
                'scope' => 'mcp:use',
            ]);

        $this->assertSame(1, DB::table('oauth_clients')->count());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function refusedRedirects(): array
    {
        return [
            'another domain' => [['https://evil.example.com/callback']],
            'localhost' => [['http://localhost:3000/callback']],
            'loopback' => [['http://127.0.0.1/callback']],
            'a lookalike host' => [['https://claude.ai.evil.com/api/mcp/auth_callback']],
            'another path on claude.ai' => [['https://claude.ai/somewhere/else']],
            'plain http' => [['http://claude.ai/api/mcp/auth_callback']],
            'a query string' => [[self::CALLBACK.'?next=https://evil.example.com']],
            'a fragment' => [[self::CALLBACK.'#x']],
            'user info' => [['https://claude.ai@evil.example.com/api/mcp/auth_callback']],
            'a custom scheme' => [['claude://callback']],
            'one good and one bad' => [[self::CALLBACK, 'https://evil.example.com/callback']],
        ];
    }

    /**
     * @param  list<string>  $redirects
     */
    #[DataProvider('refusedRedirects')]
    public function test_any_other_redirect_is_refused(array $redirects): void
    {
        $this->registerClient($redirects)
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);

        $this->assertSame(0, DB::table('oauth_clients')->count());
    }

    public function test_registration_is_rate_limited_per_address(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->registerClient()->assertCreated();
        }

        $this->registerClient()->assertTooManyRequests();
        $this->assertSame(10, DB::table('oauth_clients')->count());
    }
}
