<?php

namespace Tests\Feature\Auth;

use App\Auth\RecentMicrosoftLogin;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RecentMicrosoftLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const OID = '11111111-2222-3333-4444-555555555555';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'microsoft.recent'])
            ->get('/_pruebas/sensible', fn () => 'contenido sensible');

        $company = Company::factory()->forTenant(self::TENANT, ['segurex.com'])->create();
        $this->user = User::factory()->create([
            'tenant_id' => self::TENANT,
            'azure_oid' => self::OID,
            'company_id' => $company->id,
        ]);
    }

    private function mockRedirect(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('setScopes')->andReturnSelf();
        $provider->shouldReceive('with')
            ->with(Mockery::on(fn (array $parameters) => ($parameters['prompt'] ?? null) === 'login'))
            ->once()
            ->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize'));

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    /**
     * @param  int|false|null  $authTime  The id_token `auth_time`: null for a
     *                                    sign-in that just happened, false to leave the claim out.
     */
    private function mockCallback(string $tenant, string $oid, int|false|null $authTime = null): void
    {
        $claims = ['tid' => $tenant, 'oid' => $oid];

        if ($authTime !== false) {
            $claims['auth_time'] = $authTime ?? now()->subSeconds(20)->getTimestamp();
        }

        $user = (new MicrosoftUser)->setRaw(['id' => $oid, 'displayName' => 'Jhon', 'mail' => 'jhon@segurex.com'])
            ->map(['id' => $oid, 'name' => 'Jhon', 'email' => 'jhon@segurex.com']);
        $user->setToken('graph-access-token');

        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('getClaims')->andReturn((object) $claims);

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    /**
     * @return array{user_id: int, at: int}
     */
    private function confirmation(User $user, int $minutesAgo = 0): array
    {
        return ['user_id' => $user->id, 'at' => now()->subMinutes($minutesAgo)->getTimestamp()];
    }

    public function test_a_fresh_confirmation_lets_the_request_through(): void
    {
        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => $this->confirmation($this->user, 9)])
            ->get('/_pruebas/sensible')
            ->assertOk()
            ->assertSee('contenido sensible');
    }

    public function test_a_missing_or_expired_confirmation_redirects_to_microsoft(): void
    {
        $this->actingAs($this->user)->get('/_pruebas/sensible')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/_pruebas/sensible']));

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => $this->confirmation($this->user, 11)])
            ->get('/_pruebas/sensible')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/_pruebas/sensible']));
    }

    public function test_freshness_does_not_carry_to_another_user(): void
    {
        $other = User::factory()->create([
            'tenant_id' => self::TENANT,
            'azure_oid' => '99999999-2222-3333-4444-555555555555',
            'company_id' => $this->user->company_id,
        ]);
        $recent = $this->app->make(RecentMicrosoftLogin::class);

        $recent->confirm($this->user);

        $this->assertTrue($recent->isFresh($this->user));
        $this->assertFalse($recent->isFresh($other));
        $this->actingAs($other)
            ->get('/_pruebas/sensible')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/_pruebas/sensible']));
    }

    /**
     * Sessions from before confirmations named their user hold a bare
     * timestamp: it proves nothing about who confirmed.
     */
    public function test_an_old_timestamp_only_confirmation_is_not_fresh(): void
    {
        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => now()->getTimestamp()])
            ->get('/_pruebas/sensible')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/_pruebas/sensible']));
    }

    public function test_the_confirm_route_asks_microsoft_for_a_fresh_login(): void
    {
        $this->mockRedirect();

        $this->actingAs($this->user)
            ->get(route('auth.microsoft.confirm', ['return' => '/_pruebas/sensible']))
            ->assertRedirect('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize')
            ->assertSessionHas(RecentMicrosoftLogin::PENDING_KEY.'.return', '/_pruebas/sensible');
    }

    public function test_guests_cannot_start_a_confirmation(): void
    {
        $this->get(route('auth.microsoft.confirm', ['return' => '/admin']))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeReturns(): array
    {
        return [
            'absolute url' => ['https://evil.example/robar'],
            'protocol relative' => ['//evil.example/robar'],
            'backslash trick' => ['/\\evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'no leading slash' => ['evil.example'],
            'encoded newline' => ["/admin\r\nLocation: https://evil.example"],
        ];
    }

    #[DataProvider('unsafeReturns')]
    public function test_open_redirects_are_rejected(string $return): void
    {
        $this->mockRedirect();

        $this->actingAs($this->user)
            ->get(route('auth.microsoft.confirm', ['return' => $return]))
            ->assertSessionHas(RecentMicrosoftLogin::PENDING_KEY.'.return', '/');
    }

    public function test_a_matching_callback_confirms_and_returns(): void
    {
        $this->mockCallback(strtoupper(self::TENANT), self::OID);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect('/_pruebas/sensible')
            ->assertSessionHas(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertAuthenticatedAs($this->user);
        $this->get('/_pruebas/sensible')->assertOk();
    }

    /**
     * @return array<string, array{int|false}>
     */
    public static function staleAuthentications(): array
    {
        return [
            'stale auth_time' => [-(RecentMicrosoftLogin::DEFAULT_MAX_AUTH_AGE_SECONDS + 60)],
            'auth_time in the future' => [600],
        ];
    }

    /**
     * prompt=login is only a request: Microsoft (or a tampered flow) may
     * still answer from an existing session, so the id_token must prove the
     * credentials were entered just now.
     */
    #[DataProvider('staleAuthentications')]
    public function test_a_callback_without_a_recent_authentication_logs_the_user_out(int|false $offset): void
    {
        $this->mockCallback(self::TENANT, self::OID, $offset === false ? false : now()->getTimestamp() + $offset);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect(route('login'))
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertGuest();
        $this->assertSame('reauth.failed', Activity::query()->where('log_name', 'security')->sole()->event);
    }

    /**
     * Without the `auth_time` optional claim on the app registration no
     * re-authentication can be proven: that is a configuration problem, not
     * an attack, so the session stays and TI is told what to fix.
     */
    public function test_a_missing_auth_time_keeps_the_session_and_explains_it(): void
    {
        Log::spy();
        $this->mockCallback(self::TENANT, self::OID, false);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertStatus(503)
            ->assertSee(RecentMicrosoftLogin::UNAVAILABLE_MESSAGE)
            ->assertSee('auth_time')
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertAuthenticatedAs($this->user);
        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('reauth.unavailable', $entry->event);
        $this->assertSame('missing_auth_time', $entry->properties['reason']);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'auth_time'))->once();
    }

    public function test_another_account_without_auth_time_still_logs_the_user_out(): void
    {
        $this->mockCallback(self::TENANT, '99999999-2222-3333-4444-555555555555', false);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame('reauth.failed', Activity::query()->where('log_name', 'security')->sole()->event);
    }

    public function test_the_allowed_authentication_age_is_configurable(): void
    {
        config(['portal.reauth_max_age_seconds' => 60]);
        $this->mockCallback(self::TENANT, self::OID, now()->subSeconds(120)->getTimestamp());

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_different_microsoft_account_logs_the_user_out(): void
    {
        $this->mockCallback(self::TENANT, '99999999-2222-3333-4444-555555555555');

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => 'No pudimos completar el inicio de sesión. Inténtalo de nuevo.'])
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertGuest();
    }

    public function test_a_failed_confirmation_logs_the_user_out(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/admin', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_signed_in_user_without_a_pending_confirmation_is_sent_home(): void
    {
        $this->actingAs($this->user)
            ->get('/auth/microsoft/callback?code=abc&state=xyz')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_emergency_users_are_refused(): void
    {
        $emergency = User::factory()->emergency()->create();

        $this->actingAs($emergency)->get('/_pruebas/sensible')
            ->assertForbidden()
            ->assertSee('Crear empresas o cambiar su inquilino requiere iniciar sesión con Microsoft.');

        $this->actingAs($emergency)->get(route('auth.microsoft.confirm', ['return' => '/admin']))->assertForbidden();
    }

    // --- Security events (Auditoría) ---------------------------------------

    public function test_confirmations_are_recorded_as_security_events(): void
    {
        $this->mockCallback(self::TENANT, self::OID);

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz');

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('reauth.confirmed', $entry->event);
        $this->assertTrue($entry->causer->is($this->user));
    }

    public function test_a_confirmation_by_another_account_is_recorded(): void
    {
        $this->mockCallback(self::TENANT, '99999999-2222-3333-4444-555555555555');

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/_pruebas/sensible', 'user' => $this->user->id]])
            ->get('/auth/microsoft/callback?code=abc&state=xyz');

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('reauth.failed', $entry->event);
        $this->assertTrue($entry->causer->is($this->user));
        $this->assertStringNotContainsString('99999999', json_encode($entry->properties));
    }
}
