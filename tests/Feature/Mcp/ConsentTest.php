<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The consent screen of /oauth/authorize: who may approve a connection,
 * what they are shown, and what approving or denying does.
 */
class ConsentTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
    }

    public function test_a_guest_signs_in_with_microsoft_and_comes_back_to_the_consent_screen(): void
    {
        $url = $this->authorizeUrl($this->registeredClientId());

        $this->get($url)->assertRedirect(route('login'));

        // The same address, with the query keys sorted by Laravel.
        $intended = (string) session('url.intended');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $expected);
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $actual);
        $this->assertStringStartsWith(url('/oauth/authorize').'?', $intended);
        $this->assertEquals($expected, $actual);
    }

    public function test_the_consent_screen_names_the_client_the_redirect_and_the_account(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();

        $this->actingAs($user)
            ->get($this->authorizeUrl($clientId))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Claude')
            ->assertSee('claude.ai')
            ->assertSee('Jhon Castro')
            ->assertSee('jhon.castro@segurex.com')
            ->assertSee('Segurex Latam S.A.S.')
            ->assertSee('Solo lectura')
            ->assertSee('Tus tareas y aprobaciones')
            ->assertSee('Aprobar')
            ->assertSee('Denegar')
            ->assertSee(route('passport.authorizations.approve'), false);
    }

    /**
     * @return array<string, array{\Closure(Company): array<string, mixed>, bool}>
     */
    public static function refusedAccounts(): array
    {
        return [
            'a guest account' => [fn () => ['is_guest' => true], false],
            'an account not confirmed yet' => [fn () => ['is_guest' => null], false],
            'an inactive company' => [fn () => [], true],
            'no company' => [fn () => ['company_id' => null], false],
            'no Microsoft identity' => [fn () => ['azure_oid' => null], false],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function test_an_account_that_may_not_connect_sees_a_refusal_and_gets_no_code(\Closure $attributes, bool $inactiveCompany): void
    {
        $company = $inactiveCompany ? Company::factory()->inactive()->create() : Company::factory()->create();
        $user = $this->eligibleUser($attributes($company), $company);
        $clientId = $this->registeredClientId();

        $this->actingAs($user)
            ->get($this->authorizeUrl($clientId))
            ->assertForbidden()
            ->assertSee('Esta cuenta no puede conectar Claude')
            ->assertDontSee('Aprobar');

        $this->actingAs($user)
            ->post('/oauth/authorize', ['client_id' => $clientId, 'auth_token' => 'anything'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_the_emergency_account_may_not_connect(): void
    {
        $user = User::factory()->emergency()->create();

        $this->actingAs($user)
            ->get($this->authorizeUrl($this->registeredClientId()))
            ->assertForbidden();
    }

    public function test_approving_sends_the_code_back_to_claude(): void
    {
        $response = $this->approve($this->eligibleUser(), $this->registeredClientId());

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        $this->assertStringContainsString('code=', $location);
        $this->assertStringContainsString('state=state-123', $location);
    }

    public function test_the_consent_screen_shows_every_time(): void
    {
        $user = $this->eligibleUser();
        $this->connect($user);
        $clientId = McpConnection::query()->sole()->oauth_client_id;

        // Already approved once, with a live token: still asked again.
        $this->actingAs($user)->get($this->authorizeUrl($clientId))->assertOk()->assertSee('Aprobar');
    }

    public function test_denying_sends_access_denied_and_records_nothing(): void
    {
        $user = $this->eligibleUser();
        $clientId = $this->registeredClientId();
        $this->actingAs($user)->get($this->authorizeUrl($clientId))->assertOk();

        $response = $this->actingAs($user)->delete('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ]);

        $this->assertStringContainsString('error=access_denied', (string) $response->headers->get('Location'));
        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_approving_as_another_account_than_the_one_shown_is_refused(): void
    {
        $shown = $this->eligibleUser();
        $other = $this->eligibleUser(['email' => 'otra@segurex.com'], $shown->company);
        $clientId = $this->registeredClientId();
        $this->actingAs($shown)->get($this->authorizeUrl($clientId))->assertOk();

        // E.g. a switch of company in another tab before approving.
        $this->actingAs($other)
            ->post('/oauth/authorize', ['state' => 'state-123', 'client_id' => $clientId, 'auth_token' => session('authToken')])
            ->assertForbidden();

        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
        $this->assertSame(0, McpConnection::query()->count());
    }
}
