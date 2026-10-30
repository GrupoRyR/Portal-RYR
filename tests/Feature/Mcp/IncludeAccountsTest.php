<?php

namespace Tests\Feature\Mcp;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\AccountIntent;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Mcp\AccountConfirmation;
use App\Mcp\ConsentAccounts;
use App\Models\Company;
use App\Models\McpConnection;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * C2b: on the consent screen the approving account includes its other
 * linked accounts in the connection, each confirmed with a real Microsoft
 * sign-in of that account (`mcp_include` intent: prompt=none with
 * login_hint, one retry with prompt=login). The confirmations belong to the
 * pending authorization only; the session never changes its user, and
 * only «Aprobar» stores them.
 */
class IncludeAccountsTest extends TestCase
{
    use InteractsWithMcp, RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const LINKED_OID = '22222222-2222-2222-2222-222222222222';

    private const LINKED_HINT = 'opaque-login-hint-of-the-derivados-account';

    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize';

    /** The `state` Socialite's first redirect() stores; later ones add a number. */
    private const STATE = 'socialite-state-1';

    private Company $segurex;

    private Company $derivados;

    private User $approver;

    private User $linked;

    private string $clientId;

    /** The consent screen's address of the pending authorization. */
    private string $consentUrl = '';

    private MockInterface $provider;

    private int $redirects = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->enableMcp();

        $this->segurex = Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])
            ->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->forTenant(self::DERIVADOS_TENANT, ['derivados.com'])
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->approver = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
        ]);
        $this->linked = User::factory()->microsoft($this->derivados)->create([
            'name' => 'Jhon Castro',
            'email' => 'derivados.info@derivados.com',
            'azure_oid' => self::LINKED_OID,
            'microsoft_login_hint' => self::LINKED_HINT,
        ]);
        $this->personWith($this->approver, $this->linked);

        $this->clientId = $this->registeredClientId();

        $this->provider = Mockery::mock(MicrosoftProvider::class);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($this->provider);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Opens the consent screen as the approving account.
     *
     * @param  array<string, string|null>  $overrides
     */
    private function showConsent(?string $clientId = null, array $overrides = []): TestResponse
    {
        $this->consentUrl = $this->authorizeUrl($clientId ?? $this->clientId, $overrides);

        return $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();
    }

    /**
     * Socialite's redirect() to Microsoft with these parameters; each one
     * stores the next `state` (socialite-state-1, -2…).
     *
     * @param  array<string, string>  $parameters
     */
    private function expectMicrosoftRedirect(array $parameters): void
    {
        $this->provider->shouldReceive('setScopes')->with(MicrosoftLoginController::SCOPES)->once()->andReturnSelf();
        $this->provider->shouldReceive('with')->with($parameters)->once()->andReturnSelf();
        $this->provider->shouldReceive('redirect')->once()->andReturnUsing(function () {
            session()->put('state', 'socialite-state-'.(++$this->redirects));

            return redirect()->away(self::AUTHORIZE_URL);
        });
    }

    private function includeAccount(?User $account = null, ?string $authToken = null): TestResponse
    {
        return $this->actingAs($this->approver)->post(route('mcp.consent.include', $account ?? $this->linked), [
            'auth_token' => $authToken ?? session('authToken'),
        ]);
    }

    /**
     * Microsoft answers with an account: by default the linked one, signed
     * in silently through its existing Microsoft session.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function microsoftAnswers(array $claims = [], array $profile = []): void
    {
        $claims = array_merge([
            'tid' => self::DERIVADOS_TENANT,
            'oid' => self::LINKED_OID,
            'auth_time' => now()->subHours(3)->getTimestamp(),
        ], $claims);
        $raw = array_merge([
            'id' => $claims['oid'],
            'displayName' => 'Jhon Castro',
            'jobTitle' => null,
            'mail' => 'derivados.info@derivados.com',
            'userPrincipalName' => 'derivados.info@derivados.com',
        ], $profile);

        $user = (new MicrosoftUser)->setRaw($raw)->map([
            'id' => $raw['id'],
            'name' => $raw['displayName'],
            'email' => $raw['userPrincipalName'],
            'mail' => $raw['mail'],
            'userPrincipalName' => $raw['userPrincipalName'],
        ]);
        $user->setToken('graph-access-token');

        $this->provider->shouldReceive('user')->andReturn($user);
        $this->provider->shouldReceive('getClaims')->andReturn((object) $claims);
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::response(['value' => []]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Jhon Castro', 'jobTitle' => 'Analista']),
        ]);
    }

    private function hitCallback(string $state = self::STATE): TestResponse
    {
        return $this->actingAs($this->approver)->get('/auth/microsoft/callback?code=abc&state='.$state);
    }

    private function hitErrorCallback(string $error, string $state = self::STATE): TestResponse
    {
        return $this->actingAs($this->approver)->get('/auth/microsoft/callback?'.http_build_query([
            'error' => $error,
            'error_description' => 'AADSTS50058: A silent sign-in request was sent but no user is signed in.',
            'state' => $state,
        ]));
    }

    /**
     * The whole confirmation of the linked account on the open consent
     * screen: «Incluir», Microsoft answers with it, back to the screen.
     */
    private function confirmLinked(): TestResponse
    {
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount()->assertRedirect(self::AUTHORIZE_URL);
        $this->microsoftAnswers();
        $this->fakeGraph();

        return $this->hitCallback();
    }

    private function approveNow(?string $clientId = null): TestResponse
    {
        return $this->actingAs($this->approver)->post('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $clientId ?? $this->clientId,
            'auth_token' => session('authToken'),
        ]);
    }

    /**
     * @return list<int>
     */
    private function storedAccounts(): array
    {
        return McpConnection::query()->sole()->accounts()->orderBy('user_id')->pluck('user_id')->all();
    }

    /**
     * Back on the consent screen with a message, the linked account not
     * confirmed, the session still the approving account's, and the
     * failure in Auditoría.
     */
    private function assertRefused(TestResponse $response, string $reason, string $message = AccountConfirmation::FAILED_MESSAGE): Activity
    {
        $this->assertBackOnConsent($response)->assertSessionHas('status', $message);
        $this->assertAuthenticatedAs($this->approver);
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame(0, Activity::query()->where('event', AccountConfirmation::INCLUDED)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());

        $entry = Activity::query()->where('event', AccountConfirmation::INCLUDE_FAILED)->sole();
        $this->assertTrue($entry->causer->is($this->approver));
        $this->assertSame($reason, $entry->properties['reason']);
        $this->assertSame('Claude', $entry->properties['client']);
        $this->assertStringNotContainsString('AADSTS', json_encode($entry->properties));
        $this->assertStringNotContainsString('graph-access-token', json_encode($entry->properties));

        return $entry;
    }

    /**
     * A redirect to the consent screen of the same pending authorization:
     * the same address, with the query keys sorted by Laravel (and the
     * `prompt` AuthorizeController emptied).
     */
    private function assertBackOnConsent(TestResponse $response): TestResponse
    {
        $location = (string) $response->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($this->consentUrl, PHP_URL_QUERY), $expected);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $actual);

        $this->assertStringStartsWith(url('/oauth/authorize').'?', $location);
        $this->assertEquals($expected, array_filter($actual, fn ($value) => $value !== ''));

        return $response;
    }

    /**
     * What Passport answers when its pending authorization is gone (as on
     * «Aprobar» with another auth_token): a 403, never a code.
     */
    private function assertPassportError(TestResponse $response): void
    {
        $response->assertForbidden();

        $exception = $response->exception;
        $this->assertTrue(
            $exception instanceof InvalidAuthTokenException || $exception?->getPrevious() instanceof InvalidAuthTokenException,
            'Expected Passport\'s InvalidAuthTokenException.',
        );
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    // --- The offer -----------------------------------------------------------

    public function test_the_consent_screen_offers_the_linked_accounts_that_could_connect(): void
    {
        // Linked too, but a guest: never offered.
        $guestCompany = Company::factory()->forTenant('33333333-3333-3333-3333-333333333333')->create(['name' => 'Otra Empresa']);
        $guest = User::factory()->microsoft($guestCompany)->create(['email' => 'invitado@otra.com', 'is_guest' => true]);
        $guest->forceFill(['person_id' => $this->approver->person_id])->save();
        // Not linked: never offered.
        User::factory()->microsoft($this->derivados)->create(['email' => 'nadie@derivados.com']);

        $this->showConsent()
            ->assertSee('jhon.castro@segurex.com')
            ->assertSee('Siempre incluida')
            ->assertSee('derivados.info@derivados.com')
            ->assertSee('Soluciones y Derivados')
            ->assertSee('Incluir')
            ->assertSee(route('mcp.consent.include', $this->linked), false)
            ->assertDontSee('invitado@otra.com')
            ->assertDontSee('nadie@derivados.com')
            ->assertDontSee('Confirmada');
    }

    public function test_an_account_without_linked_accounts_sees_no_offer(): void
    {
        $this->linked->forceFill(['person_id' => null])->save();
        $this->approver->forceFill(['person_id' => null])->save();

        $this->showConsent()->assertSee('Siempre incluida')->assertDontSee('Incluir');
    }

    public function test_the_include_routes_only_take_a_post_or_delete_of_a_signed_in_account(): void
    {
        foreach (['mcp.consent.include', 'mcp.consent.exclude'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth:web', $middleware);
            $this->assertContains('mcp.account', $middleware);
            $this->assertContains('mcp.enabled', $middleware);
        }

        $this->actingAs($this->approver)->get(route('mcp.consent.include', $this->linked))->assertMethodNotAllowed();
        $this->app['auth']->forgetGuards();
        $this->post(route('mcp.consent.include', $this->linked))->assertRedirect(route('login'));
    }

    // --- «Incluir» -----------------------------------------------------------

    public function test_including_sends_microsoft_the_linked_account_silently(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);

        $this->includeAccount()
            ->assertRedirect(self::AUTHORIZE_URL)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.type', AccountIntent::MCP_INCLUDE)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.user_id', $this->approver->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.target_id', $this->linked->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.attempt', 1)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.state', self::STATE);

        $this->assertAuthenticatedAs($this->approver);
        $this->assertNotNull(session('authRequest'));
    }

    public function test_including_needs_the_pending_authorization_of_this_screen(): void
    {
        $this->showConsent();
        $this->provider->shouldReceive('redirect')->never();

        $this->assertPassportError($this->includeAccount(authToken: 'another-token'));

        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    public function test_including_without_a_pending_authorization_is_the_passport_error(): void
    {
        $this->provider->shouldReceive('redirect')->never();

        $this->assertPassportError($this->includeAccount(authToken: 'anything'));

        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notOffered(): array
    {
        return [
            'an account of nobody' => ['unlinked'],
            'the approving account itself' => ['same'],
            'a guest account' => ['guest'],
            'an emergency account' => ['emergency'],
            'an account of an inactive company' => ['inactive company'],
            'an account of another person' => ['another person'],
        ];
    }

    /**
     * A 404, as for the company switch: the answer does not reveal which
     * accounts are linked.
     */
    #[DataProvider('notOffered')]
    public function test_an_account_that_is_not_offered_cannot_be_included(string $case): void
    {
        $this->showConsent();
        $this->provider->shouldReceive('redirect')->never();
        $account = $this->linked;

        match ($case) {
            'unlinked' => $this->linked->forceFill(['person_id' => null])->save(),
            'same' => $account = $this->approver,
            'guest' => $this->linked->forceFill(['is_guest' => true])->save(),
            'emergency' => $this->linked->forceFill(['is_emergency' => true])->save(),
            'inactive company' => $this->derivados->forceFill(['is_active' => false])->save(),
            'another person' => $this->personWith($this->linked, User::factory()->microsoft()->create()),
        };

        $this->includeAccount($account)->assertNotFound();

        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    // --- Microsoft answers -----------------------------------------------------

    public function test_the_linked_account_answering_confirms_it_for_this_authorization_only(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 15));
        $this->showConsent();

        $response = $this->confirmLinked();

        $this->assertBackOnConsent($response)
            ->assertSessionHas('status', 'Confirmaste tu cuenta de Soluciones y Derivados. Se incluirá al aprobar.')
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        // The session never changes its user; nothing is stored yet.
        $this->assertAuthenticatedAs($this->approver);
        $this->assertSame(0, McpConnection::query()->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_SWITCHED)->count());
        $this->assertNull($this->linked->fresh()->last_login_at);

        // Its row is refreshed as a sign-in would, with proven data.
        $this->assertSame('Analista', $this->linked->fresh()->job_title);

        $entry = Activity::query()->where('event', AccountConfirmation::INCLUDED)->sole();
        $this->assertTrue($entry->causer->is($this->approver));
        $this->assertTrue($entry->subject->is($this->linked));
        $this->assertSame('Claude', $entry->properties['client']);
        $this->assertSame($this->linked->id, $entry->properties['included_user_id']);
        $this->assertSame(
            'Confirmó su cuenta de Soluciones y Derivados (derivados.info@derivados.com) para Claude («Claude»)',
            app(AuditPresenter::class)->activity($entry)->what,
        );

        // Back on the same pending authorization: shown as confirmed.
        $this->actingAs($this->approver)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Confirmaste tu cuenta de Soluciones y Derivados.')
            ->assertSee('Confirmada a las 9:15')
            ->assertSee('Quitar')
            ->assertSee(route('mcp.consent.exclude', $this->linked), false);
    }

    public function test_approving_stores_the_approving_account_and_the_confirmed_ones(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 15));
        $this->showConsent();
        $this->confirmLinked();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 20));
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();

        $tokens = $this->exchange($this->clientId, [
            'code' => $this->codeFrom($this->approveNow()),
            'verifier' => $this->verifier,
        ])->assertOk();

        $this->assertSame([$this->approver->id, $this->linked->id], $this->storedAccounts());
        $this->assertNull(session(ConsentAccounts::SESSION_KEY));

        // Each account with the time of its own confirmation, in the app's
        // time zone like every other date the portal stores.
        $this->assertSame(
            [$this->approver->id => '2026-10-30 09:20:00', $this->linked->id => '2026-10-30 09:15:00'],
            DB::table('mcp_connection_accounts')->orderBy('user_id')->pluck('confirmed_at', 'user_id')->all(),
        );

        $connected = Activity::query()->where('event', 'mcp.connected')->sole();
        $this->assertSame(
            [
                ['user_id' => $this->approver->id, 'company' => 'Segurex Latam S.A.S.', 'email' => 'jhon.castro@segurex.com'],
                ['user_id' => $this->linked->id, 'company' => 'Soluciones y Derivados', 'email' => 'derivados.info@derivados.com'],
            ],
            $connected->properties['accounts'],
        );
        $this->assertSame(
            'Conectó Claude («Claude») con su cuenta de Segurex Latam S.A.S. (jhon.castro@segurex.com) e incluyó su cuenta de Soluciones y Derivados (derivados.info@derivados.com)',
            app(AuditPresenter::class)->activity($connected)->what,
        );

        // quien_soy answers with every usable account, the approving one first.
        $call = $this->mcp((string) $tokens->json('access_token'), 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();
        $this->assertSame(['cuentas' => [
            ['nombre' => 'Jhon Castro', 'correo' => 'jhon.castro@segurex.com', 'empresa' => 'Segurex'],
            ['nombre' => 'Jhon Castro', 'correo' => 'derivados.info@derivados.com', 'empresa' => 'Derivados'],
        ]], $call->json('result.structuredContent'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function wrongAccounts(): array
    {
        return [
            'another account of the same tenant' => [['oid' => '44444444-4444-4444-4444-444444444444']],
            'the same object ID in another tenant' => [['tid' => self::SEGUREX_TENANT]],
            'the approving account itself' => [['tid' => self::SEGUREX_TENANT, 'oid' => 'approver']],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[DataProvider('wrongAccounts')]
    public function test_microsoft_answering_with_another_account_confirms_nothing(array $claims): void
    {
        if (($claims['oid'] ?? null) === 'approver') {
            $claims['oid'] = $this->approver->azure_oid;
        }

        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->microsoftAnswers($claims, ['mail' => 'jhon.castro@segurex.com', 'userPrincipalName' => 'jhon.castro@segurex.com']);

        $entry = $this->assertRefused($this->hitCallback(), 'wrong_account', AccountConfirmation::WRONG_ACCOUNT_MESSAGE);
        $this->assertSame(strtolower($claims['tid'] ?? self::DERIVADOS_TENANT), $entry->properties['tenant_id']);

        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();
        $this->approveNow()->assertRedirect();
        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableTargets(): array
    {
        return [
            'unlinked meanwhile' => ['unlinked'],
            'made a guest meanwhile' => ['guest'],
            'made an emergency account meanwhile' => ['emergency'],
            'its company deactivated meanwhile' => ['inactive company'],
            'deleted meanwhile' => ['deleted'],
        ];
    }

    #[DataProvider('unusableTargets')]
    public function test_a_linked_account_that_can_no_longer_connect_when_microsoft_answers_is_refused(string $case): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->microsoftAnswers();
        $this->fakeGraph();

        match ($case) {
            'unlinked' => $this->linked->forceFill(['person_id' => null])->save(),
            'guest' => $this->linked->forceFill(['is_guest' => true])->save(),
            'emergency' => $this->linked->forceFill(['is_emergency' => true])->save(),
            'inactive company' => $this->derivados->forceFill(['is_active' => false])->save(),
            'deleted' => $this->linked->delete(),
        };

        $this->assertRefused($this->hitCallback(), 'target_unavailable');
    }

    public function test_a_sign_in_rule_failing_confirms_nothing(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        // Microsoft answers with the right account, but without an email.
        $this->microsoftAnswers([], ['mail' => null, 'userPrincipalName' => null]);

        $this->assertRefused($this->hitCallback(), 'missing_email');
    }

    public function test_a_guest_sign_in_of_the_linked_account_confirms_nothing(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        // Signed in through another home tenant (B2B guest): never home.
        $this->microsoftAnswers(['idp' => 'https://sts.windows.net/'.self::SEGUREX_TENANT.'/']);
        $this->fakeGraph();

        $this->assertRefused($this->hitCallback(), 'account_not_allowed');
        $this->assertFalse($this->linked->fresh()->is_guest);
    }

    // --- Errors and the retry ----------------------------------------------

    public function test_a_silent_sign_in_that_needs_the_person_retries_once_asking_for_credentials(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();

        $this->expectMicrosoftRedirect(['prompt' => 'login', 'login_hint' => self::LINKED_HINT]);
        $this->hitErrorCallback('login_required')
            ->assertRedirect(self::AUTHORIZE_URL)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.type', AccountIntent::MCP_INCLUDE)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.attempt', 2)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.state', 'socialite-state-2');
        $this->assertAuthenticatedAs($this->approver);

        // The retry failing too ends it: no third trip to Microsoft.
        $entry = $this->assertRefused($this->hitErrorCallback('interaction_required', 'socialite-state-2'), 'interaction_required');
        $this->assertSame(2, $entry->properties['attempt']);
        $this->assertSame(2, $this->redirects);
    }

    public function test_the_retry_answering_with_the_linked_account_confirms_it(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->expectMicrosoftRedirect(['prompt' => 'login', 'login_hint' => self::LINKED_HINT]);
        $this->hitErrorCallback('consent_required');
        $this->microsoftAnswers(['auth_time' => now()->getTimestamp()]);
        $this->fakeGraph();

        $this->assertBackOnConsent($this->hitCallback('socialite-state-2'));

        $this->assertSame([$this->linked->id], array_keys(session(ConsentAccounts::SESSION_KEY.'.accounts')));
        $this->assertAuthenticatedAs($this->approver);
    }

    public function test_the_retry_stays_bound_to_the_pending_authorization_it_started_for(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $binding = session(AccountIntent::SESSION_KEY.'.binding');
        $this->expectMicrosoftRedirect(['prompt' => 'login', 'login_hint' => self::LINKED_HINT]);
        $this->hitErrorCallback('login_required')->assertSessionHas(AccountIntent::SESSION_KEY.'.binding', $binding);

        // Another client's consent screen opens before Microsoft answers the retry.
        $this->showConsent($this->registeredClientId());
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->assertPassportError($this->hitCallback('socialite-state-2'));
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame(0, Activity::query()->where('event', AccountConfirmation::INCLUDED)->count());
    }

    public function test_other_microsoft_errors_do_not_retry(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();

        $this->assertRefused($this->hitErrorCallback('access_denied'), 'access_denied');
        $this->assertSame(1, $this->redirects);
    }

    // --- Intents that may not be used ---------------------------------------

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableIntents(): array
    {
        return [
            'expired' => [['expires_at' => 0], self::STATE],
            'of another state' => [[], 'socialite-state-of-another-redirect'],
            'of another user' => [['user_id' => 'other'], self::STATE],
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    #[DataProvider('unusableIntents')]
    public function test_an_unusable_intent_confirms_nothing(array $intent, string $state): void
    {
        $other = User::factory()->microsoft($this->segurex)->create();
        $this->showConsent();
        $this->microsoftAnswers();
        $this->fakeGraph();

        session()->put(AccountIntent::SESSION_KEY, array_merge([
            'type' => AccountIntent::MCP_INCLUDE,
            'user_id' => $this->approver->id,
            'target_id' => $this->linked->id,
            'attempt' => 1,
            'state' => self::STATE,
            'expires_at' => now()->getTimestamp() + AccountIntent::TTL_SECONDS,
        ], $intent, ($intent['user_id'] ?? null) === 'other' ? ['user_id' => $other->id] : []));

        $this->assertBackOnConsent($this->hitCallback($state))->assertSessionHas('status', AccountIntent::UNUSABLE_MESSAGE);

        $this->assertAuthenticatedAs($this->approver);
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame(0, Activity::query()->where('event', AccountConfirmation::INCLUDED)->count());
    }

    public function test_replaying_the_callback_confirms_nothing_more(): void
    {
        $this->showConsent();
        $this->confirmLinked();
        session()->forget(ConsentAccounts::SESSION_KEY.'.accounts');
        session()->put(ConsentAccounts::SESSION_KEY.'.accounts', []);

        $this->hitCallback()->assertRedirect('/');

        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame(1, Activity::query()->where('event', AccountConfirmation::INCLUDED)->count());
        $this->assertAuthenticatedAs($this->approver);
    }

    // --- The pending authorization ------------------------------------------

    public function test_a_lost_pending_authorization_gives_the_passport_error_and_confirms_nothing(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->microsoftAnswers();
        $this->fakeGraph();

        // E.g. approved or denied in another tab meanwhile.
        session()->forget(['authToken', 'authRequest']);

        $this->assertPassportError($this->hitCallback());

        $this->assertAuthenticatedAs($this->approver);
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame('authorization_lost', Activity::query()->where('event', AccountConfirmation::INCLUDE_FAILED)->sole()->properties['reason']);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_a_lost_pending_authorization_on_a_microsoft_error_gives_the_passport_error(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        session()->forget(['authToken', 'authRequest']);

        $this->assertPassportError($this->hitErrorCallback('login_required'));

        $this->assertSame(1, $this->redirects);
    }

    public function test_another_pending_authorization_starts_without_the_confirmations(): void
    {
        $this->showConsent();
        $this->confirmLinked();

        // Claude starts again (another state and PKCE challenge).
        $this->showConsent(overrides: ['state' => 'state-456'])->assertDontSee('Confirmada');
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));

        $this->actingAs($this->approver)->post('/oauth/authorize', [
            'state' => 'state-456',
            'client_id' => $this->clientId,
            'auth_token' => session('authToken'),
        ])->assertRedirect();

        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function otherPendingAuthorizations(): array
    {
        return [
            'another client' => [true],
            'another request of the same client' => [false],
        ];
    }

    /**
     * «Incluir» on one consent screen, then another pending authorization
     * opens in the same browser (another tab, or a page that opens a
     * publicly registered client's link) before Microsoft answers: the
     * answer confirms nothing for either one.
     */
    #[DataProvider('otherPendingAuthorizations')]
    public function test_a_confirmation_never_lands_on_another_pending_authorization(bool $otherClient): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount()->assertRedirect(self::AUTHORIZE_URL);

        $clientId = $otherClient ? $this->registeredClientId() : $this->clientId;
        $this->showConsent($clientId, ['state' => 'state-456'])->assertDontSee('Confirmada');
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->assertPassportError($this->hitCallback());

        $this->assertAuthenticatedAs($this->approver);
        $this->assertSame([], session(ConsentAccounts::SESSION_KEY.'.accounts'));
        $this->assertSame(0, Activity::query()->where('event', AccountConfirmation::INCLUDED)->count());
        $this->assertSame('authorization_lost', Activity::query()->where('event', AccountConfirmation::INCLUDE_FAILED)->sole()->properties['reason']);

        // The other screen is not pre-filled, and approving it stores only the approver.
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk()->assertDontSee('Confirmada');
        $this->actingAs($this->approver)->post('/oauth/authorize', [
            'state' => 'state-456',
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ])->assertRedirect();
        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    public function test_a_microsoft_error_for_another_pending_authorization_does_not_retry(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->showConsent($this->registeredClientId());

        $this->assertPassportError($this->hitErrorCallback('login_required'));

        $this->assertSame(1, $this->redirects);
        $this->assertSame('authorization_lost', Activity::query()->where('event', AccountConfirmation::INCLUDE_FAILED)->sole()->properties['reason']);
    }

    public function test_reopening_the_same_request_while_microsoft_answers_still_confirms(): void
    {
        $this->showConsent();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::LINKED_HINT]);
        $this->includeAccount();
        $this->assertSame(session(ConsentAccounts::SESSION_KEY.'.fingerprint'), session(AccountIntent::SESSION_KEY.'.binding'));

        // E.g. the consent tab reloaded: a new auth_token, the same request.
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->assertBackOnConsent($this->hitCallback());
        $this->assertSame([$this->linked->id], array_keys(session(ConsentAccounts::SESSION_KEY.'.accounts')));
    }

    public function test_the_confirmations_never_reach_another_client(): void
    {
        $this->showConsent();
        $this->confirmLinked();

        $otherClient = $this->registeredClientId();
        $this->showConsent($otherClient)->assertDontSee('Confirmada');

        $this->approveNow($otherClient)->assertRedirect();

        $connection = McpConnection::query()->sole();
        $this->assertSame($otherClient, $connection->oauth_client_id);
        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    public function test_approving_with_another_auth_token_is_the_passport_error_and_stores_nothing(): void
    {
        $this->showConsent();
        $this->confirmLinked();
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();

        $this->assertPassportError($this->actingAs($this->approver)->post('/oauth/authorize', [
            'state' => 'state-123',
            'client_id' => $this->clientId,
            'auth_token' => 'not-the-one',
        ]));

        $this->assertSame(0, McpConnection::query()->count());
    }

    public function test_quitar_takes_a_confirmation_back_before_approving(): void
    {
        $this->showConsent();
        $this->confirmLinked();
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();

        $response = $this->actingAs($this->approver)->delete(route('mcp.consent.exclude', $this->linked), [
            'auth_token' => session('authToken'),
        ]);

        $this->assertBackOnConsent($response);
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk()->assertDontSee('Confirmada');

        $this->approveNow()->assertRedirect();
        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    public function test_an_account_unlinked_after_its_confirmation_is_not_stored(): void
    {
        $this->showConsent();
        $this->confirmLinked();
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();

        $this->linked->forceFill(['person_id' => null])->save();
        $this->approveNow()->assertRedirect();

        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    public function test_approving_again_replaces_the_accounts_with_the_new_confirmations(): void
    {
        $this->showConsent();
        $this->confirmLinked();
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();
        $this->approveNow()->assertRedirect();
        $this->assertSame([$this->approver->id, $this->linked->id], $this->storedAccounts());
        $connectionId = McpConnection::query()->sole()->id;

        // Approved again without confirming the linked account: it leaves.
        $this->showConsent(overrides: ['state' => 'state-789']);
        $this->actingAs($this->approver)->post('/oauth/authorize', [
            'state' => 'state-789',
            'client_id' => $this->clientId,
            'auth_token' => session('authToken'),
        ])->assertRedirect();

        $this->assertSame($connectionId, McpConnection::query()->sole()->id);
        $this->assertSame([$this->approver->id], $this->storedAccounts());
    }

    public function test_the_home_page_is_untouched(): void
    {
        $this->showConsent();
        $this->confirmLinked();

        $this->actingAs($this->approver)->get('/')->assertOk()->assertDontSee('Incluir');
        $this->assertAuthenticatedAs($this->approver);
    }

    // --- On every call ---------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function droppedOnTheNextCall(): array
    {
        return [
            'unlinked' => ['unlinked'],
            'deleted' => ['deleted'],
            'made a guest' => ['guest'],
            'made an emergency account' => ['emergency'],
            'its company deactivated' => ['inactive company'],
        ];
    }

    /**
     * Each call checks the stored accounts again: one that can no longer
     * connect drops out, and the approving account keeps working.
     */
    #[DataProvider('droppedOnTheNextCall')]
    public function test_an_included_account_that_can_no_longer_connect_drops_out_on_the_next_call(string $case): void
    {
        $this->showConsent();
        $this->confirmLinked();
        $this->actingAs($this->approver)->get($this->consentUrl)->assertOk();
        $token = (string) $this->exchange($this->clientId, [
            'code' => $this->codeFrom($this->approveNow()),
            'verifier' => $this->verifier,
        ])->assertOk()->json('access_token');
        $this->assertCount(2, $this->mcp($token, 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->json('result.structuredContent.cuentas'));

        match ($case) {
            'unlinked' => $this->linked->forceFill(['person_id' => null])->save(),
            'deleted' => $this->linked->delete(),
            'guest' => $this->linked->forceFill(['is_guest' => true])->save(),
            'emergency' => $this->linked->forceFill(['is_emergency' => true])->save(),
            'inactive company' => $this->derivados->forceFill(['is_active' => false])->save(),
        };

        $call = $this->mcp($token, 'tools/call', ['name' => 'quien_soy', 'arguments' => []])->assertOk();
        $this->assertSame(
            [['nombre' => 'Jhon Castro', 'correo' => 'jhon.castro@segurex.com', 'empresa' => 'Segurex']],
            $call->json('result.structuredContent.cuentas'),
        );
    }

    // --- Helpers for the code -------------------------------------------------

    private function codeFrom(TestResponse $approved): string
    {
        $location = (string) $approved->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }
}
