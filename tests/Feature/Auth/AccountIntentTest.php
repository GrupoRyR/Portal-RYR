<?php

namespace Tests\Feature\Auth;

use App\Auth\AccountIntent;
use App\Auth\LoginFailed;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The shared Microsoft callback before any sign-in work: an `error` answer
 * from Microsoft, and the single-use account intents of the later linking
 * and company-switching flows.
 */
class AccountIntentTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    /** What Socialite's redirect() would have stored in the session. */
    private const STATE = 'socialite-state-1234567890';

    private const ERROR_DESCRIPTION = 'AADSTS65004: User declined to consent to access the app.';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->forTenant(self::TENANT, ['segurex.com'])->create();
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    private function intents(): AccountIntent
    {
        return $this->app->make(AccountIntent::class);
    }

    /**
     * Starts an intent and binds it to {@see STATE}, as a flow does right
     * after Socialite's redirect() stored that state.
     */
    private function startIntent(?User $user = null): void
    {
        $this->intents()->start(AccountIntent::LINK, $user ?? $this->user);
        session()->put('state', self::STATE);
        $this->intents()->bindState();
    }

    private function callbackRequest(string $state = self::STATE): Request
    {
        return Request::create('/auth/microsoft/callback', 'GET', ['code' => 'abc', 'state' => $state]);
    }

    private function errorCallbackUrl(): string
    {
        return '/auth/microsoft/callback?'.http_build_query([
            'error' => 'access_denied',
            'error_description' => self::ERROR_DESCRIPTION,
            'state' => self::STATE,
        ]);
    }

    // --- Microsoft answers with an error -----------------------------------

    public function test_an_error_callback_keeps_the_signed_in_session(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->startIntent();

        $this->actingAs($this->user)
            ->get($this->errorCallbackUrl())
            ->assertRedirect('/')
            ->assertSessionHas('status', MicrosoftLoginController::MICROSOFT_ERROR_MESSAGE)
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_error_callback_during_reauthentication_does_not_sign_out(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($this->user)
            ->withSession([RecentMicrosoftLogin::PENDING_KEY => ['return' => '/admin/empresas/segurex', 'user' => $this->user->id]])
            ->get($this->errorCallbackUrl())
            ->assertRedirect('/')
            ->assertSessionHas('status', MicrosoftLoginController::MICROSOFT_ERROR_MESSAGE)
            ->assertSessionMissing(RecentMicrosoftLogin::PENDING_KEY)
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_error_callback_for_a_guest_shows_the_retry_page(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->get($this->errorCallbackUrl())
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => LoginFailed::RETRY]);

        $this->assertGuest();
        $this->get(route('login'))->assertDontSee('AADSTS65004');

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('login.microsoft_rejected', $entry->event);
        $this->assertSame('microsoft_error', $entry->properties['reason']);
        $this->assertStringNotContainsString('AADSTS', json_encode($entry->properties));
    }

    // --- Account intents ---------------------------------------------------

    public function test_an_intent_is_single_use(): void
    {
        $this->startIntent();

        $first = $this->intents()->pull($this->callbackRequest(), $this->user);
        $second = $this->intents()->pull($this->callbackRequest(), $this->user);

        $this->assertSame(AccountIntent::LINK, $first['type'] ?? null);
        $this->assertSame($this->user->id, $first['user_id']);
        $this->assertNull($second);
        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    public function test_an_intent_with_another_state_is_ignored(): void
    {
        $this->startIntent();

        $this->assertNull($this->intents()->pull($this->callbackRequest('another-state'), $this->user));
        // It is gone even though it was read with the wrong state.
        $this->assertNull($this->intents()->pull($this->callbackRequest(), $this->user));

        // An intent never bound to a state matches no callback, not even one without a state.
        $this->intents()->start(AccountIntent::LINK, $this->user);
        $this->assertNull($this->intents()->pull($this->callbackRequest(''), $this->user));
    }

    public function test_an_intent_of_another_user_is_ignored(): void
    {
        $other = User::factory()->microsoft($this->company)->create();
        $this->startIntent($this->user);

        $this->assertNull($this->intents()->pull($this->callbackRequest(), $other));
        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    public function test_an_expired_intent_is_ignored(): void
    {
        $this->freezeTime();

        $this->startIntent();
        $this->travel(AccountIntent::TTL_SECONDS - 1)->seconds();
        $this->assertNotNull($this->intents()->pull($this->callbackRequest(), $this->user));

        $this->startIntent();
        $this->travel(AccountIntent::TTL_SECONDS)->seconds();
        $this->assertNull($this->intents()->pull($this->callbackRequest(), $this->user));
    }

    public function test_starting_an_intent_clears_other_pending_flows(): void
    {
        $this->startIntent();
        session()->put(RecentMicrosoftLogin::PENDING_KEY, ['return' => '/admin', 'user' => $this->user->id]);

        $this->intents()->start(AccountIntent::SWITCH, $this->user, $this->user);

        $this->assertFalse(session()->has(RecentMicrosoftLogin::PENDING_KEY));
        $this->assertSame(AccountIntent::SWITCH, session(AccountIntent::SESSION_KEY.'.type'));
        $this->assertNull(session(AccountIntent::SESSION_KEY.'.state'));
    }

    public function test_starting_a_reauthentication_discards_a_pending_intent(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('setScopes')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize'));
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
        $this->startIntent();

        $this->actingAs($this->user)
            ->get(route('auth.microsoft.confirm', ['return' => '/admin']))
            ->assertSessionMissing(AccountIntent::SESSION_KEY)
            ->assertSessionHas(RecentMicrosoftLogin::PENDING_KEY.'.return', '/admin');
    }

    /**
     * A pending intent ends the callback whatever its outcome: it never
     * becomes a re-authentication (or a sign-in), even with a pending
     * re-authentication whose identity would match.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function unusableIntents(): array
    {
        return [
            'unknown type' => ['merge', self::STATE, 600],
            'another state' => ['link', 'another-state', 600],
            'expired' => ['link', self::STATE, 0],
        ];
    }

    #[DataProvider('unusableIntents')]
    public function test_an_intent_never_falls_through_to_reauthentication(string $type, string $state, int $expiresIn): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($this->user)
            ->withSession([
                'state' => self::STATE,
                RecentMicrosoftLogin::PENDING_KEY => ['return' => '/admin', 'user' => $this->user->id],
                AccountIntent::SESSION_KEY => [
                    'type' => $type,
                    'user_id' => $this->user->id,
                    'target_id' => null,
                    'attempt' => 1,
                    'state' => $state,
                    'expires_at' => now()->getTimestamp() + $expiresIn,
                ],
            ])
            ->get('/auth/microsoft/callback?code=abc&state='.self::STATE)
            ->assertRedirect('/')
            ->assertSessionHas('status', AccountIntent::UNUSABLE_MESSAGE)
            ->assertSessionMissing(AccountIntent::SESSION_KEY)
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(0, Activity::query()->where('log_name', 'security')->count());
    }
}
