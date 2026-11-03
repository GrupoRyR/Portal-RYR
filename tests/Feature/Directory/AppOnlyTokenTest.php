<?php

namespace Tests\Feature\Directory;

use App\Microsoft\AppOnlyToken;
use App\Microsoft\GraphAppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * App-only Microsoft Graph tokens for any tenant (client credentials),
 * used by the directory copy (App\Directory\DirectorySync).
 */
class AppOnlyTokenTest extends TestCase
{
    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private const OTHER_TENANT = '22222222-2222-2222-2222-222222222222';

    private const SECRET = 'SECRET-VALUE-NEVER-SHOWN';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => self::SECRET,
        ]);
    }

    private function tokens(): AppOnlyToken
    {
        return $this->app->make(AppOnlyToken::class);
    }

    public function test_it_asks_for_a_graph_token_of_the_tenant_and_caches_it_per_tenant(): void
    {
        Http::fake(['login.microsoftonline.com/*' => fn (Request $request) => Http::response([
            'access_token' => 'token-for-'.explode('/', parse_url($request->url(), PHP_URL_PATH))[1],
            'expires_in' => 3600,
        ])]);

        $this->assertSame('token-for-'.self::TENANT, $this->tokens()->for(self::TENANT));
        $this->assertSame('token-for-'.self::TENANT, $this->tokens()->for(strtoupper(self::TENANT)));
        $this->assertSame('token-for-'.self::OTHER_TENANT, $this->tokens()->for(self::OTHER_TENANT));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://graph.microsoft.com/.default'
            && $request['client_id'] === 'client-id');
    }

    public function test_forget_drops_the_cached_token_of_one_tenant(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->push(['access_token' => 'first', 'expires_in' => 3600])
            ->push(['access_token' => 'second', 'expires_in' => 3600])]);

        $this->assertSame('first', $this->tokens()->for(self::TENANT));
        $this->tokens()->forget(self::TENANT);
        $this->assertSame('second', $this->tokens()->for(self::TENANT));
    }

    public function test_a_refused_token_raises_the_status_and_aadsts_codes_but_never_the_secret(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'error' => 'unauthorized_client',
            'error_description' => 'AADSTS700016: Application with identifier client-id was not found. '.self::SECRET,
            'error_codes' => [700016],
        ], 400)]);

        try {
            $this->tokens()->for(self::TENANT);
            $this->fail('Expected a GraphAppException.');
        } catch (GraphAppException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame([700016], $e->aadsts);
            $this->assertStringContainsString('AADSTS700016', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_an_unreachable_sign_in_raises_without_echoing_the_request(): void
    {
        Http::fake(['login.microsoftonline.com/*' => fn () => throw new ConnectionException('cURL error 6 '.self::SECRET)]);

        try {
            $this->tokens()->for(self::TENANT);
            $this->fail('Expected a GraphAppException.');
        } catch (GraphAppException $e) {
            $this->assertNull($e->status);
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_without_a_client_id_or_secret_it_asks_for_nothing(): void
    {
        config(['services.microsoft.client_secret' => '']);
        Http::fake();

        $this->expectException(GraphAppException::class);

        try {
            $this->tokens()->for(self::TENANT);
        } finally {
            Http::assertNothingSent();
        }
    }
}
