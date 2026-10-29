<?php

namespace Tests\Feature\Account;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\AccountIntent;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Account\SwitchAccountController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Microsoft\UserGroup;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Pwa\PortalUserKey;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Switching company: POST /cuenta/cambiar/{user} and the Microsoft
 * callback of the `switch` intent. Linking never gives a session: the
 * switch is a real Microsoft sign-in with the target account (silent
 * first, one interactive retry), and anything else leaves the current
 * session as it was.
 */
class SwitchAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const TARGET_OID = '22222222-2222-2222-2222-222222222222';

    private const TARGET_HINT = 'opaque-login-hint-of-the-derivados-account';

    /** What Socialite's redirect() stored for the pending switch. */
    private const STATE = 'socialite-state-1234567890';

    /** What Socialite's redirect() stores for the retry. */
    private const RETRY_STATE = 'socialite-state-of-the-retry';

    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize';

    private const ERROR_DESCRIPTION = 'AADSTS50058: A silent sign-in request was sent but no user is signed in.';

    private Company $segurex;

    private Company $derivados;

    private User $current;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->segurex = Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])->create(['name' => 'Segurex Latam']);
        $this->derivados = Company::factory()->forTenant(self::DERIVADOS_TENANT, ['derivados.com'])->create(['name' => 'Soluciones y Derivados']);
        $this->current = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Edward Ramanan',
            'email' => 'edward@segurex.com',
        ]);
        $this->target = User::factory()->microsoft($this->derivados)->create([
            'name' => 'Edward Ramanan',
            'email' => 'edward@derivados.com',
            'azure_oid' => self::TARGET_OID,
            'microsoft_login_hint' => self::TARGET_HINT,
        ]);

        $this->personWith($this->current, $this->target);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    /**
     * Socialite's redirect() to Microsoft, which stores {@see RETRY_STATE}.
     *
     * @param  array<string, string>  $parameters
     */
    private function expectMicrosoftRedirect(array $parameters): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('setScopes')->with(MicrosoftLoginController::SCOPES)->once()->andReturnSelf();
        $provider->shouldReceive('with')->with($parameters)->once()->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturnUsing(function () {
            session()->put('state', self::RETRY_STATE);

            return redirect()->away(self::AUTHORIZE_URL);
        });
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    /**
     * The signed-in account, just sent to Microsoft to sign in to the
     * target. `marker` stands for the rest of the current session.
     *
     * @param  array<string, mixed>  $intent
     */
    private function switching(int $attempt = 1, array $intent = []): static
    {
        return $this->actingAs($this->current)->withSession([
            'state' => self::STATE,
            'marker' => 'current session',
            AccountIntent::SESSION_KEY => array_merge([
                'type' => AccountIntent::SWITCH,
                'user_id' => $this->current->id,
                'target_id' => $this->target->id,
                'attempt' => $attempt,
                'state' => self::STATE,
                'expires_at' => now()->getTimestamp() + AccountIntent::TTL_SECONDS,
            ], $intent),
        ]);
    }

    private function hitCallback(int $attempt = 1): TestResponse
    {
        return $this->switching($attempt)->get('/auth/microsoft/callback?code=abc&state='.self::STATE);
    }

    private function hitErrorCallback(string $error, int $attempt = 1): TestResponse
    {
        return $this->switching($attempt)->get('/auth/microsoft/callback?'.http_build_query([
            'error' => $error,
            'error_description' => self::ERROR_DESCRIPTION,
            'state' => self::STATE,
        ]));
    }

    /**
     * Microsoft answers with an account: by default the target, signed in
     * silently through its existing Microsoft session (an old auth_time).
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function microsoftAnswers(array $claims = [], array $profile = []): void
    {
        $claims = array_merge([
            'tid' => self::DERIVADOS_TENANT,
            'oid' => self::TARGET_OID,
            'auth_time' => now()->subHours(3)->getTimestamp(),
        ], $claims);
        $raw = array_merge([
            'id' => $claims['oid'],
            'displayName' => 'Edward Ramanan',
            'jobTitle' => null,
            'mail' => 'edward@derivados.com',
            'userPrincipalName' => 'edward@derivados.com',
        ], $profile);

        $user = (new MicrosoftUser)->setRaw($raw)->map([
            'id' => $raw['id'],
            'name' => $raw['displayName'],
            'email' => $raw['userPrincipalName'],
            'mail' => $raw['mail'],
            'userPrincipalName' => $raw['userPrincipalName'],
        ]);
        $user->setToken('graph-access-token');

        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('getClaims')->andReturn((object) $claims);

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::response(['value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'bbbbbbbb-0000-0000-0000-000000000009', 'displayName' => 'APP-Cartera'],
            ]]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Edward Ramanan', 'jobTitle' => 'Analista']),
        ]);
    }

    /**
     * Everything a failed switch must leave as it was.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'users' => User::query()->orderBy('id')
                ->get(['id', 'person_id', 'company_id', 'email', 'job_title', 'microsoft_login_hint', 'is_guest', 'last_login_at', 'updated_at'])
                ->toArray(),
            'people' => Person::query()->orderBy('id')->pluck('id')->all(),
            'groups' => UserGroup::query()->count(),
        ];
    }

    private function assertKeptTheCurrentSession(TestResponse $response, string $message, string $reason): Activity
    {
        $response->assertRedirect('/')
            ->assertSessionHas('status', $message)
            ->assertSessionHas('marker', 'current session')
            ->assertSessionMissing(AccountIntent::SESSION_KEY)
            ->assertHeaderMissing('Clear-Site-Data');

        $this->assertAuthenticatedAs($this->current);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_SWITCHED)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_SWITCH_FAILED)->sole();
        $this->assertTrue($entry->causer->is($this->current));
        $this->assertSame($reason, $entry->properties['reason']);
        $this->assertSame($this->target->id, $entry->properties['to_user_id']);
        $this->assertStringNotContainsString('AADSTS', json_encode($entry->properties));
        $this->assertStringNotContainsString('graph-access-token', json_encode($entry->properties));

        return $entry;
    }

    // --- POST /cuenta/cambiar/{user} -------------------------------------

    public function test_the_route_only_takes_a_post_with_a_csrf_token(): void
    {
        $this->assertSame(url('/cuenta/cambiar/'.$this->target->id), route('account.switch', $this->target));

        $middleware = app('router')->getRoutes()->getByName('account.switch')->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('throttle:20,1', $middleware);

        $this->actingAs($this->current)->get('/cuenta/cambiar/'.$this->target->id)->assertMethodNotAllowed();

        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($this->current)->post('/cuenta/cambiar/'.$this->target->id)->assertStatus(419);
        $this->assertFalse(session()->has(AccountIntent::SESSION_KEY));
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->post('/cuenta/cambiar/'.$this->target->id)->assertRedirect(route('login'));
    }

    public function test_posting_sends_the_person_to_microsoft_silently_as_the_target_account(): void
    {
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => self::TARGET_HINT]);

        $this->actingAs($this->current)
            ->post(route('account.switch', $this->target))
            ->assertRedirect(self::AUTHORIZE_URL)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.type', AccountIntent::SWITCH)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.user_id', $this->current->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.target_id', $this->target->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.attempt', 1)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.state', self::RETRY_STATE);

        // Nothing changes before Microsoft answers.
        $this->assertAuthenticatedAs($this->current);
    }

    public function test_the_login_hint_falls_back_to_the_email(): void
    {
        $this->target->forceFill(['microsoft_login_hint' => null])->save();
        $this->expectMicrosoftRedirect(['prompt' => 'none', 'login_hint' => 'edward@derivados.com']);

        $this->actingAs($this->current)->post(route('account.switch', $this->target))->assertRedirect(self::AUTHORIZE_URL);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notSwitchable(): array
    {
        return [
            'an account of nobody' => ['unlinked'],
            'an account of another person' => ['another person'],
            'the same account' => ['same account'],
            'a missing account' => ['missing'],
            'a company that is not active' => ['inactive company'],
            'a guest account' => ['guest'],
            'an account not known as a home account' => ['guest unknown'],
            'an emergency account' => ['emergency target'],
            'a local account' => ['local target'],
            'from an emergency account' => ['emergency current'],
        ];
    }

    /**
     * A 404, never a 403: the answer does not reveal which accounts are
     * linked.
     */
    #[DataProvider('notSwitchable')]
    public function test_a_target_that_is_not_another_usable_account_of_the_person_is_not_found(string $case): void
    {
        Socialite::shouldReceive('driver')->never();
        $current = $this->current;
        $targetId = $this->target->id;

        match ($case) {
            'unlinked' => User::query()->update(['person_id' => null]),
            'another person' => $this->personWith($this->target, User::factory()->microsoft()->create()),
            'same account' => $targetId = $this->current->id,
            'missing' => $targetId = 999999,
            'inactive company' => $this->derivados->forceFill(['is_active' => false])->save(),
            'guest' => $this->target->forceFill(['is_guest' => true])->save(),
            'guest unknown' => $this->target->forceFill(['is_guest' => null])->save(),
            'emergency target' => $this->target->forceFill(['is_emergency' => true])->save(),
            'local target' => $this->target->forceFill(['tenant_id' => null, 'azure_oid' => null])->save(),
            'emergency current' => $current = User::factory()->emergency()->create(['person_id' => $this->target->person_id]),
        };

        $this->actingAs($current)
            ->post('/cuenta/cambiar/'.$targetId)
            ->assertNotFound()
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($current);
    }

    // --- The callback: switched --------------------------------------------

    public function test_the_target_account_answering_signs_it_in_on_a_new_session(): void
    {
        config(['portal.super_admin_emails' => ['edward@derivados.com']]);
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->startSession();
        $oldId = session()->getId();
        $oldToken = session()->token();

        $response = $this->switching()
            ->withCookie(config('session.cookie'), $oldId)
            ->get('/auth/microsoft/callback?code=abc&state='.self::STATE);

        $response->assertRedirect('/')
            ->assertHeader('Clear-Site-Data', '"cache"')
            ->assertHeader(PortalUserKey::HEADER, PortalUserKey::for($this->target))
            ->assertSessionHas('status', 'Ahora estás en tu cuenta de Soluciones y Derivados.')
            ->assertSessionMissing('marker')
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($this->target);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) session()->token());
        $this->assertNotSame($oldToken, session()->token());

        // A real sign-in of the target: profile, groups, roles and its record.
        $target = $this->target->fresh();
        $this->assertNotNull($target->last_login_at);
        $this->assertSame('Analista', $target->job_title);
        $this->assertSame(['APP-Cartera'], $target->groups()->pluck('display_name')->all());
        $this->assertTrue($target->hasRole('super_admin'));
        $this->assertSame(2, User::query()->count());

        $signIn = Activity::query()->where('event', SecurityLog::MICROSOFT)->sole();
        $this->assertTrue($signIn->causer->is($this->target));
        $this->assertSame(['super_admin'], $signIn->properties['roles_added']);

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_SWITCHED)->sole();
        $this->assertTrue($entry->causer->is($this->current));
        $this->assertTrue($entry->subject->is($this->target));
        $this->assertSame($this->current->id, $entry->properties['from_user_id']);
        $this->assertSame($this->segurex->id, $entry->properties['from_company_id']);
        $this->assertSame($this->target->id, $entry->properties['to_user_id']);
        $this->assertSame($this->derivados->id, $entry->properties['to_company_id']);
        $this->assertStringNotContainsString('graph-access-token', json_encode($entry->properties));
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_SWITCH_FAILED)->count());
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function notRecentSignIns(): array
    {
        return [
            'credentials entered hours ago' => [3 * 3600],
            'no auth_time' => [null],
        ];
    }

    /**
     * A silent switch rides on an existing Microsoft session: it is not a
     * recent confirmation, and the one of the previous account never
     * carries over.
     */
    #[DataProvider('notRecentSignIns')]
    public function test_a_silent_switch_is_not_a_recent_confirmation(?int $secondsAgo): void
    {
        $this->microsoftAnswers(['auth_time' => $secondsAgo === null ? null : now()->getTimestamp() - $secondsAgo]);
        $this->fakeGraph();

        $this->switching()
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => ['user_id' => $this->current->id, 'at' => now()->getTimestamp()]])
            ->get('/auth/microsoft/callback?code=abc&state='.self::STATE)
            ->assertRedirect('/')
            ->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);

        $this->assertAuthenticatedAs($this->target);
        $this->assertFalse($this->app->make(RecentMicrosoftLogin::class)->isFresh($this->target));
        $this->assertFalse($this->app->make(RecentMicrosoftLogin::class)->isFresh($this->current));
    }

    public function test_a_switch_with_credentials_entered_just_now_is_a_recent_confirmation(): void
    {
        $this->microsoftAnswers(['auth_time' => now()->getTimestamp() - 5]);
        $this->fakeGraph();

        $this->hitCallback(2)->assertRedirect('/');

        $this->assertAuthenticatedAs($this->target);
        $this->assertTrue($this->app->make(RecentMicrosoftLogin::class)->isFresh($this->target));
    }

    // --- The callback: retried ---------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function interactionErrors(): array
    {
        return [
            'login_required' => ['login_required'],
            'interaction_required' => ['interaction_required'],
            'consent_required' => ['consent_required'],
            'account_selection_required' => ['account_selection_required'],
        ];
    }

    #[DataProvider('interactionErrors')]
    public function test_a_silent_switch_that_needs_the_person_is_retried_once_interactively(string $error): void
    {
        $this->expectMicrosoftRedirect(['prompt' => 'login', 'login_hint' => self::TARGET_HINT]);

        $this->hitErrorCallback($error)
            ->assertRedirect(self::AUTHORIZE_URL)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.type', AccountIntent::SWITCH)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.user_id', $this->current->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.target_id', $this->target->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.attempt', 2)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.state', self::RETRY_STATE)
            ->assertSessionHas('marker', 'current session');

        $this->assertAuthenticatedAs($this->current);
        $this->assertSame(0, Activity::query()->where('log_name', SecurityLog::LOG)->count());
    }

    #[DataProvider('interactionErrors')]
    public function test_a_failed_retry_keeps_the_current_session(string $error): void
    {
        Socialite::shouldReceive('driver')->never();
        $before = $this->snapshot();

        $entry = $this->assertKeptTheCurrentSession($this->hitErrorCallback($error, 2), SwitchAccountController::FAILED_MESSAGE, $error);

        $this->assertSame(2, $entry->properties['attempt']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_any_other_error_is_not_retried(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->assertKeptTheCurrentSession($this->hitErrorCallback('access_denied'), SwitchAccountController::FAILED_MESSAGE, 'access_denied');
    }

    public function test_the_error_code_is_sanitized_before_it_is_recorded(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->assertKeptTheCurrentSession(
            $this->hitErrorCallback('server_error<script>'),
            SwitchAccountController::FAILED_MESSAGE,
            'server_errorscript',
        );
    }

    public function test_a_retry_is_not_started_for_a_target_that_is_no_longer_usable(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->derivados->forceFill(['is_active' => false])->save();

        $this->assertKeptTheCurrentSession($this->hitErrorCallback('login_required'), SwitchAccountController::FAILED_MESSAGE, 'target_unavailable');
    }

    // --- The callback: refused, the current session kept -------------------

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function otherAccounts(): array
    {
        return [
            'the current account' => [['tid' => self::SEGUREX_TENANT, 'oid' => 'current']],
            'another account of the target tenant' => [['oid' => '33333333-3333-3333-3333-333333333333']],
            'the target object ID in another tenant' => [['tid' => self::SEGUREX_TENANT]],
        ];
    }

    /**
     * @param  array<string, string>  $claims
     */
    #[DataProvider('otherAccounts')]
    public function test_another_account_answering_changes_nothing(array $claims): void
    {
        if (($claims['oid'] ?? null) === 'current') {
            $claims['oid'] = $this->current->azure_oid;
        }
        $this->microsoftAnswers($claims, ['mail' => 'someone@segurex.com', 'userPrincipalName' => 'someone@segurex.com']);
        $before = $this->snapshot();

        $entry = $this->assertKeptTheCurrentSession($this->hitCallback(), SwitchAccountController::WRONG_ACCOUNT_MESSAGE, 'wrong_account');

        $this->assertSame($before, $this->snapshot());
        $this->assertSame($claims['tid'] ?? self::DERIVADOS_TENANT, $entry->properties['tenant_id']);
    }

    public function test_a_failed_sign_in_check_keeps_the_current_session(): void
    {
        // The target answered, but without an email the sign-in refuses it.
        $this->microsoftAnswers([], ['mail' => null, 'userPrincipalName' => null]);
        $before = $this->snapshot();

        $this->assertKeptTheCurrentSession($this->hitCallback(), SwitchAccountController::FAILED_MESSAGE, 'missing_email');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_target_that_stopped_being_usable_is_not_signed_in(): void
    {
        Socialite::shouldReceive('driver')->never();
        $this->derivados->forceFill(['is_active' => false])->save();
        $before = $this->snapshot();

        $this->assertKeptTheCurrentSession($this->hitCallback(), SwitchAccountController::FAILED_MESSAGE, 'target_unavailable');

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableIntents(): array
    {
        return [
            'of another user' => ['another user'],
            'with another state' => ['another state'],
            'expired' => ['expired'],
        ];
    }

    #[DataProvider('unusableIntents')]
    public function test_a_switch_intent_that_is_not_this_callbacks_is_refused(string $case): void
    {
        Socialite::shouldReceive('driver')->never();

        $intent = match ($case) {
            'another user' => ['user_id' => User::factory()->microsoft($this->segurex)->create()->id],
            'another state' => ['state' => 'another-state'],
            'expired' => ['expires_at' => now()->getTimestamp()],
        };

        $this->switching(1, $intent)
            ->get('/auth/microsoft/callback?code=abc&state='.self::STATE)
            ->assertRedirect('/')
            ->assertSessionHas('status', AccountIntent::UNUSABLE_MESSAGE)
            ->assertSessionHas('marker', 'current session')
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($this->current);

        // Nor does an error answer retry it.
        $this->switching(1, $intent)
            ->get('/auth/microsoft/callback?error=login_required&state='.self::STATE)
            ->assertRedirect('/')
            ->assertSessionHas('status', MicrosoftLoginController::MICROSOFT_ERROR_MESSAGE)
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($this->current);
        $this->assertSame(0, Activity::query()->where('log_name', SecurityLog::LOG)->count());
    }

    // --- Auditoría ---------------------------------------------------------

    public function test_auditoria_describes_switches_and_failed_ones(): void
    {
        $presenter = $this->app->make(AuditPresenter::class);

        SecurityLog::accountSwitched($this->current, $this->target);
        SecurityLog::accountSwitchFailed($this->current, $this->target, 'wrong_account', 1, self::SEGUREX_TENANT);
        SecurityLog::accountSwitchFailed($this->current, $this->target, 'login_required', 2, null);

        [$switched, $wrong, $failed] = Activity::query()->where('log_name', SecurityLog::LOG)->orderBy('id')->get()->all();

        $this->assertSame('Cambió a su cuenta de Soluciones y Derivados (edward@derivados.com)', $presenter->activity($switched)->what);
        $this->assertSame(
            'Cambio de empresa rechazado · Microsoft respondió con otra cuenta · hacia Soluciones y Derivados (edward@derivados.com) · inquilino '.self::SEGUREX_TENANT,
            $presenter->activity($wrong)->what,
        );
        $this->assertSame(
            'Cambio de empresa rechazado · Microsoft pidió iniciar sesión · hacia Soluciones y Derivados (edward@derivados.com)',
            $presenter->activity($failed)->what,
        );
    }
}
