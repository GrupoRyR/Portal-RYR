<?php

namespace Tests\Feature\Account;

use App\Audit\AuditPresenter;
use App\Audit\SecurityLog;
use App\Auth\AccountIntent;
use App\Auth\LinkedAccounts;
use App\Auth\LinkRefused;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Microsoft\UserGroup;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkedAccountNotice;
use App\Notifications\LinkedAccountNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use RuntimeException;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Linking another company's account: GET/POST /cuenta/vincular and the
 * Microsoft callback of the `link` intent. Two proofs (a recent
 * confirmation and a sign-in to the other account), no new session, and a
 * refusal leaves no trace.
 */
class LinkAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const ANDINA_TENANT = 'cccccccc-0000-0000-0000-000000000003';

    private const OTHER_OID = '22222222-2222-2222-2222-222222222222';

    /** What Socialite's redirect() stored in the session. */
    private const STATE = 'socialite-state-1234567890';

    private const AUTHORIZE_URL = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize';

    private Company $segurex;

    private Company $derivados;

    private User $current;

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
    }

    /**
     * @return array{user_id: int, at: int}
     */
    private function freshConfirmation(?User $user = null): array
    {
        return ['user_id' => ($user ?? $this->current)->id, 'at' => now()->getTimestamp()];
    }

    /**
     * A signed-in user who confirmed with Microsoft and was just sent there
     * to sign in to the other account.
     */
    private function linking(?User $user = null): static
    {
        $user ??= $this->current;

        return $this->actingAs($user)->withSession([
            'state' => self::STATE,
            RecentMicrosoftLogin::SESSION_KEY => $this->freshConfirmation($user),
            AccountIntent::SESSION_KEY => [
                'type' => AccountIntent::LINK,
                'user_id' => $user->id,
                'target_id' => null,
                'attempt' => 1,
                'state' => self::STATE,
                'expires_at' => now()->getTimestamp() + AccountIntent::TTL_SECONDS,
            ],
        ]);
    }

    private function hitCallback(?User $user = null): TestResponse
    {
        return $this->linking($user)->get('/auth/microsoft/callback?code=abc&state='.self::STATE);
    }

    /**
     * Microsoft answers with the account the person picked: by default
     * Edward's Derivados account.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function microsoftAnswers(array $claims = [], array $profile = []): void
    {
        $claims = array_merge(['tid' => self::DERIVADOS_TENANT, 'oid' => self::OTHER_OID], $claims);
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

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    /**
     * Everything a refusal must leave as it was.
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

    /**
     * @param  array<string, mixed>  $before
     */
    private function assertRefused(TestResponse $response, string $reason, array $before, ?string $tenantId = self::DERIVADOS_TENANT): void
    {
        $response->assertRedirect('/')
            ->assertSessionHas('status', (new LinkRefused($reason))->getMessage())
            ->assertSessionMissing(AccountIntent::SESSION_KEY);

        $this->assertAuthenticatedAs($this->current);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_LINKED)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_LINK_REFUSED)->sole();
        $this->assertTrue($entry->causer->is($this->current));
        $this->assertSame($reason, $entry->properties['reason']);
        $this->assertSame($tenantId, $entry->properties['tenant_id']);
    }

    // --- The page ----------------------------------------------------------

    public function test_the_page_needs_a_recent_microsoft_confirmation(): void
    {
        $this->actingAs($this->current)
            ->get(route('account.link'))
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/cuenta/vincular']));
    }

    public function test_the_page_explains_linking_and_continues_with_microsoft(): void
    {
        $andina = Company::factory()->forTenant(self::ANDINA_TENANT)->create(['name' => 'Andina Química']);
        $this->personWith($this->current, User::factory()->microsoft($andina)->create(['email' => 'edward@andina.com']));

        $this->actingAs($this->current)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => $this->freshConfirmation()])
            ->get('/cuenta/vincular')
            ->assertOk()
            ->assertSee('Vincular otra cuenta')
            ->assertSee('Tu sesión actual no cambia')
            ->assertSee('Andina Química')
            ->assertSee('edward@andina.com')
            ->assertSee('action="'.route('account.link.start').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Continuar con Microsoft');
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/cuenta/vincular')->assertRedirect(route('login'));
        $this->post('/cuenta/vincular')->assertRedirect(route('login'));
    }

    public function test_accounts_without_microsoft_cannot_link(): void
    {
        Socialite::shouldReceive('driver')->never();

        foreach ([User::factory()->emergency()->create(), User::factory()->create(['company_id' => $this->segurex->id])] as $account) {
            $this->actingAs($account)->get('/cuenta/vincular')->assertForbidden()->assertViewIs('errors.microsoft-required');
            $this->actingAs($account)
                ->withSession([RecentMicrosoftLogin::SESSION_KEY => $this->freshConfirmation($account)])
                ->post('/cuenta/vincular')
                ->assertForbidden()
                ->assertViewIs('errors.microsoft-required');
        }
    }

    public function test_posting_without_a_recent_confirmation_goes_back_to_the_page(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->actingAs($this->current)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => ['user_id' => $this->current->id, 'at' => now()->subMinutes(11)->getTimestamp()]])
            ->post('/cuenta/vincular')
            ->assertRedirect(route('account.link'))
            ->assertSessionMissing(AccountIntent::SESSION_KEY);
    }

    public function test_posting_sends_the_person_to_microsoft_to_choose_the_other_account(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('setScopes')->with(MicrosoftLoginController::SCOPES)->once()->andReturnSelf();
        $provider->shouldReceive('with')->with(['prompt' => 'select_account'])->once()->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturnUsing(function () {
            session()->put('state', self::STATE);

            return redirect()->away(self::AUTHORIZE_URL);
        });
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->actingAs($this->current)
            ->withSession([RecentMicrosoftLogin::SESSION_KEY => $this->freshConfirmation()])
            ->post('/cuenta/vincular')
            ->assertRedirect(self::AUTHORIZE_URL)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.type', AccountIntent::LINK)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.user_id', $this->current->id)
            ->assertSessionHas(AccountIntent::SESSION_KEY.'.state', self::STATE);
    }

    // --- The callback: linked ----------------------------------------------

    public function test_the_other_account_is_linked_without_signing_it_in(): void
    {
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->hitCallback()
            ->assertRedirect('/')
            ->assertSessionHas('status', 'Vinculaste tu cuenta de Soluciones y Derivados.')
            ->assertSessionMissing(AccountIntent::SESSION_KEY)
            ->assertSessionHas(RecentMicrosoftLogin::SESSION_KEY.'.user_id', $this->current->id);

        $this->assertAuthenticatedAs($this->current);

        $other = User::query()->where('azure_oid', self::OTHER_OID)->sole();
        $this->assertTrue($other->company->is($this->derivados));
        $this->assertSame('edward@derivados.com', $other->email);
        $this->assertFalse($other->is_guest);
        $this->assertSame('Analista', $other->job_title);
        $this->assertSame(['APP-Cartera'], $other->groups()->pluck('display_name')->all());
        $this->assertNull($other->last_login_at);

        $person = Person::query()->sole();
        $this->assertSame($person->id, $other->person_id);
        $this->assertSame($person->id, $this->current->fresh()->person_id);

        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ROLES_SYNCED)->count(), 'No role changed.');
        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_LINKED)->sole();
        $this->assertTrue($entry->causer->is($this->current));
        $this->assertTrue($entry->subject->is($other));
    }

    public function test_an_existing_account_keeps_its_own_sign_in_record(): void
    {
        $lastSignIn = now()->subDay()->startOfSecond();
        $other = User::factory()->microsoft($this->derivados)->create([
            'azure_oid' => self::OTHER_OID,
            'last_login_at' => $lastSignIn,
        ]);
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/')->assertSessionHas('status', 'Vinculaste tu cuenta de Soluciones y Derivados.');

        $other->refresh();
        $this->assertTrue($other->last_login_at->equalTo($lastSignIn));
        $this->assertNotNull($other->person_id);
        $this->assertSame(2, User::query()->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());
        $this->assertAuthenticatedAs($this->current);
    }

    public function test_a_third_company_joins_the_same_person(): void
    {
        $andina = Company::factory()->forTenant(self::ANDINA_TENANT, ['andina.com'])->create(['name' => 'Andina Química']);
        $derivados = User::factory()->microsoft($this->derivados)->create();
        $person = $this->personWith($this->current, $derivados);
        $this->microsoftAnswers(['tid' => self::ANDINA_TENANT], ['mail' => 'edward@andina.com', 'userPrincipalName' => 'edward@andina.com']);
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/')->assertSessionHas('status', 'Vinculaste tu cuenta de Andina Química.');

        $third = User::query()->where('azure_oid', self::OTHER_OID)->sole();
        $this->assertTrue($third->company->is($andina));
        $this->assertSame(1, Person::query()->count());
        $this->assertEqualsCanonicalizing(
            [$this->current->id, $derivados->id, $third->id],
            $person->accounts()->pluck('id')->all(),
        );
        $this->assertAuthenticatedAs($this->current);
    }

    // --- The callback: refused, without a trace ----------------------------

    public function test_the_same_microsoft_account_is_refused(): void
    {
        $before = $this->snapshot();
        $this->microsoftAnswers(['tid' => self::SEGUREX_TENANT, 'oid' => $this->current->azure_oid], ['mail' => 'edward@segurex.com']);

        $this->assertRefused($this->hitCallback(), LinkRefused::SAME_ACCOUNT, $before, self::SEGUREX_TENANT);
    }

    public function test_a_guest_sign_in_is_refused(): void
    {
        $before = $this->snapshot();
        // Edward's Segurex account as a B2B guest of the Derivados tenant.
        $this->microsoftAnswers(['idp' => 'https://sts.windows.net/'.self::SEGUREX_TENANT.'/'], ['mail' => 'edward@segurex.com']);

        $this->assertRefused($this->hitCallback(), LinkRefused::GUEST_ACCOUNT, $before);
    }

    public function test_a_signed_in_guest_account_cannot_link(): void
    {
        $this->current->forceFill(['is_guest' => true])->save();
        $before = $this->snapshot();
        $this->microsoftAnswers();

        $this->assertRefused($this->hitCallback(), LinkRefused::GUEST_ACCOUNT, $before);
    }

    public function test_a_signed_in_account_not_known_as_a_home_account_is_asked_to_confirm_again(): void
    {
        $this->current->forceFill(['is_guest' => null])->save();
        $before = $this->snapshot();
        $this->microsoftAnswers();

        $response = $this->hitCallback();

        $this->assertRefused($response, LinkRefused::GUEST_UNKNOWN, $before);
        $response->assertSessionHas('status', 'Confirma de nuevo tu cuenta y vuelve a intentarlo.');
        $this->assertSame(
            'Vinculación de cuentas rechazada · falta confirmar la cuenta · inquilino '.self::DERIVADOS_TENANT,
            $this->app->make(AuditPresenter::class)->activity(Activity::query()->where('event', SecurityLog::ACCOUNT_LINK_REFUSED)->sole())->what,
        );
    }

    public function test_an_organization_without_access_is_refused(): void
    {
        $before = $this->snapshot();
        $this->microsoftAnswers(['tid' => 'FFFFFFFF-0000-0000-0000-000000000000']);

        $this->assertRefused($this->hitCallback(), 'tenant_not_allowed', $before, 'ffffffff-0000-0000-0000-000000000000');
    }

    public function test_an_inactive_company_is_refused(): void
    {
        $this->derivados->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->microsoftAnswers();

        $this->assertRefused($this->hitCallback(), 'tenant_not_allowed', $before);
    }

    public function test_an_answer_without_an_email_is_refused(): void
    {
        $before = $this->snapshot();
        $this->microsoftAnswers([], ['mail' => null, 'userPrincipalName' => 'edward_segurex.com#EXT#@derivados.onmicrosoft.com']);

        $this->assertRefused($this->hitCallback(), 'missing_email', $before);
    }

    public function test_an_account_of_the_same_company_is_refused(): void
    {
        $before = $this->snapshot();
        $this->microsoftAnswers(['tid' => self::SEGUREX_TENANT], ['mail' => 'otro@segurex.com']);

        $this->assertRefused($this->hitCallback(), LinkRefused::SAME_COMPANY, $before, self::SEGUREX_TENANT);
    }

    public function test_a_company_the_person_already_has_is_refused(): void
    {
        $this->personWith($this->current, User::factory()->microsoft($this->derivados)->create());
        $before = $this->snapshot();
        $this->microsoftAnswers();

        $this->assertRefused($this->hitCallback(), LinkRefused::COMPANY_ALREADY_LINKED, $before);
    }

    public function test_an_account_of_another_person_is_refused(): void
    {
        $andina = Company::factory()->forTenant(self::ANDINA_TENANT)->create();
        $other = User::factory()->microsoft($this->derivados)->create(['azure_oid' => self::OTHER_OID, 'job_title' => 'Contador']);
        $this->personWith($this->current, User::factory()->microsoft(Company::factory()->create())->create());
        $this->personWith($other, User::factory()->microsoft($andina)->create());
        $before = $this->snapshot();
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->assertRefused($this->hitCallback(), LinkRefused::DIFFERENT_PEOPLE, $before);
        $this->assertSame('Contador', $other->fresh()->job_title, 'The other account is not refreshed either.');
        $this->assertSame(
            'Vinculación de cuentas rechazada · ya está vinculada con otras cuentas · inquilino '.self::DERIVADOS_TENANT,
            $this->app->make(AuditPresenter::class)->activity(Activity::query()->where('event', SecurityLog::ACCOUNT_LINK_REFUSED)->sole())->what,
        );
    }

    public function test_a_microsoft_failure_is_refused(): void
    {
        $before = $this->snapshot();
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->assertRefused($this->hitCallback(), 'provider_error', $before, null);
    }

    /**
     * A concurrent link takes the company after the rules were read (here
     * the rules see nothing at all): admit() has written the new account,
     * the unique index stops the link, and everything is rolled back.
     */
    public function test_a_link_stopped_by_the_unique_index_after_admit_leaves_no_trace(): void
    {
        $this->app->instance(LinkedAccounts::class, new class($this->app->make(LinkedAccountNotifier::class)) extends LinkedAccounts
        {
            public function check(User $current, User $other): void {}
        });
        $this->personWith($this->current, User::factory()->microsoft($this->derivados)->create());
        $before = $this->snapshot();
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->assertRefused($this->hitCallback(), LinkRefused::COMPANY_ALREADY_LINKED, $before);
        $this->assertSame(0, User::query()->where('azure_oid', self::OTHER_OID)->count());
    }

    /**
     * A database error while admit() refreshes the other account (on MySQL a
     * deadlock has already rolled the transaction back) must end the link,
     * not be taken for a Graph failure.
     */
    public function test_a_database_failure_while_admitting_the_other_account_refuses_the_link(): void
    {
        $this->microsoftAnswers();
        $this->fakeGraph();
        Schema::drop('user_groups');

        $this->hitCallback()
            ->assertRedirect('/')
            ->assertSessionHas('status', LinkRefused::RETRY);

        $this->assertAuthenticatedAs($this->current);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Person::query()->count());
        $this->assertSame('provider_error', Activity::query()->where('event', SecurityLog::ACCOUNT_LINK_REFUSED)->sole()->properties['reason']);
    }

    public function test_role_changes_on_the_other_account_reach_the_audit(): void
    {
        config(['portal.super_admin_emails' => ['edward@derivados.com']]);
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->hitCallback()->assertSessionHas('status', 'Vinculaste tu cuenta de Soluciones y Derivados.');

        $other = User::query()->where('azure_oid', self::OTHER_OID)->sole();
        $this->assertTrue($other->hasRole('super_admin'));

        $entry = Activity::query()->where('event', SecurityLog::ROLES_SYNCED)->sole();
        $this->assertTrue($entry->subject->is($other));
        $this->assertSame(['super_admin'], $entry->properties['roles_added']);
        $this->assertSame([], $entry->properties['roles_removed']);
    }

    public function test_a_refusal_is_readable_in_the_audit(): void
    {
        $this->microsoftAnswers(['tid' => self::SEGUREX_TENANT], ['mail' => 'otro@segurex.com']);

        $this->hitCallback();

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_LINK_REFUSED)->sole();
        $this->assertSame(
            'Vinculación de cuentas rechazada · misma empresa · inquilino '.self::SEGUREX_TENANT,
            $this->app->make(AuditPresenter::class)->activity($entry)->what,
        );
    }

    // --- The notice --------------------------------------------------------

    public function test_every_account_of_the_person_gets_the_notice(): void
    {
        Notification::fake();
        $andina = Company::factory()->forTenant(self::ANDINA_TENANT, ['andina.com'])->create(['name' => 'Andina Química']);
        $derivados = User::factory()->microsoft($this->derivados)->create();
        $this->personWith($this->current, $derivados);
        $this->microsoftAnswers(['tid' => self::ANDINA_TENANT], ['mail' => 'edward@andina.com', 'userPrincipalName' => 'edward@andina.com']);
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $third = User::query()->where('azure_oid', self::OTHER_OID)->sole();
        Notification::assertSentTo([$this->current, $derivados, $third], LinkedAccountNotice::class);
        Notification::assertSentTimes(LinkedAccountNotice::class, 3);
        Notification::assertSentTo(
            $derivados,
            LinkedAccountNotice::class,
            fn (LinkedAccountNotice $notice) => $notice->firstEmail === 'edward@segurex.com' && $notice->secondEmail === 'edward@andina.com',
        );
    }

    public function test_the_notice_names_both_accounts_in_the_recipients_brand(): void
    {
        $this->freezeTime();
        $this->derivados->update(['color_dark' => '#0B3B36']);
        $other = User::factory()->microsoft($this->derivados)->create(['email' => 'edward@derivados.com']);

        $mail = LinkedAccountNotice::for($this->current, $other)->toMail($other);
        $html = (string) $mail->render();

        $this->assertSame('Se vincularon dos cuentas tuyas del portal', $mail->subject);
        $this->assertStringContainsString('Segurex Latam: edward@segurex.com', $html);
        $this->assertStringContainsString('Soluciones y Derivados: edward@derivados.com', $html);
        $this->assertStringContainsString(now()->timezone(config('app.timezone'))->locale('es')->isoFormat('D [de] MMMM [de] YYYY'), $html);
        $this->assertStringContainsString('Si no fuiste tú, avisa a TI.', $html);
        $this->assertStringContainsString('background-color: #0B3B36', $html);
    }

    public function test_a_mail_failure_does_not_block_the_link(): void
    {
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
        $this->microsoftAnswers();
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/')->assertSessionHas('status', 'Vinculaste tu cuenta de Soluciones y Derivados.');

        $this->assertNotNull($this->current->fresh()->person_id);
        $this->assertContains('Linked account notice could not be sent.', $warnings);
    }
}
