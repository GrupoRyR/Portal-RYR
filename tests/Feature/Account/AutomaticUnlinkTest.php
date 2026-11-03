<?php

namespace Tests\Feature\Account;

use App\Audit\SecurityLog;
use App\Auth\LinkedAccounts;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A sign-in moves an account to the company its email domain now points
 * to. When its person already has an account there, the moving account
 * is unlinked (`company_changed`): one account per company, and the
 * sign-in itself goes on.
 */
class AutomaticUnlinkTest extends TestCase
{
    use RefreshDatabase;

    /** Shared by Segurex and SDC, told apart by the email domain. */
    private const SHARED_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const OID = '11111111-1111-1111-1111-111111111111';

    private Company $segurex;

    private Company $sdc;

    private User $moving;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::response(['value' => []]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Luis Gómez', 'jobTitle' => null]),
        ]);

        $this->segurex = Company::factory()->forTenant(self::SHARED_TENANT, ['segurex.com'])->create();
        $this->sdc = Company::factory()->forTenant(self::SHARED_TENANT, ['sdc.com'], primary: false)->create(['name' => 'SDC']);

        // Luis's Segurex account; its mail is now on the SDC domain.
        $this->moving = User::factory()->microsoft($this->segurex)->create(['azure_oid' => self::OID, 'email' => 'luis@segurex.com']);
    }

    private function personWith(User ...$accounts): Person
    {
        $person = Person::query()->create();

        foreach ($accounts as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }

        return $person;
    }

    private function identity(): MicrosoftIdentity
    {
        return new MicrosoftIdentity(self::SHARED_TENANT, self::OID, 'luis@sdc.com', 'Luis Gómez', 'graph-access-token', self::SHARED_TENANT);
    }

    private function signInMovingToSdc(): void
    {
        $user = (new MicrosoftUser)
            ->setRaw(['id' => self::OID, 'displayName' => 'Luis Gómez', 'mail' => 'luis@sdc.com', 'userPrincipalName' => 'luis@sdc.com'])
            ->map(['id' => self::OID, 'name' => 'Luis Gómez', 'email' => 'luis@sdc.com']);
        $user->setToken('graph-access-token');

        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('getClaims')->andReturn((object) ['tid' => self::SHARED_TENANT, 'oid' => self::OID]);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get('/auth/microsoft/callback?code=abc&state=xyz')->assertRedirect('/');
    }

    public function test_a_sign_in_into_a_company_the_person_already_has_unlinks_the_account(): void
    {
        $sdcAccount = User::factory()->microsoft($this->sdc)->create();
        $third = User::factory()->microsoft(Company::factory()->create())->create();
        $person = $this->personWith($this->moving, $sdcAccount, $third);

        $this->signInMovingToSdc();

        $this->assertAuthenticatedAs($this->moving);
        $this->moving->refresh();
        $this->assertTrue($this->moving->company->is($this->sdc));
        $this->assertNull($this->moving->person_id);
        $this->assertEqualsCanonicalizing([$sdcAccount->id, $third->id], $person->accounts()->pluck('id')->all());

        $entry = Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->sole();
        $this->assertNull($entry->causer);
        $this->assertTrue($entry->subject->is($this->moving));
        $this->assertSame(LinkedAccounts::COMPANY_CHANGED, $entry->properties['reason']);
        $this->assertSame(1, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());
    }

    public function test_a_person_left_with_one_account_is_removed(): void
    {
        $sdcAccount = User::factory()->microsoft($this->sdc)->create();
        $person = $this->personWith($this->moving, $sdcAccount);

        $this->signInMovingToSdc();

        $this->assertModelMissing($person);
        $this->assertNull($this->moving->fresh()->person_id);
        $this->assertNull($sdcAccount->fresh()->person_id);
        $this->assertEqualsCanonicalizing(
            [$this->moving->id, $sdcAccount->id],
            Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->where('properties->reason', LinkedAccounts::COMPANY_CHANGED)->pluck('subject_id')->all(),
        );
    }

    /**
     * It happens during a sign-in and the mail is sent synchronously, so
     * the automatic unlink tells no one, not even the dissolved person.
     */
    public function test_an_unlink_during_a_sign_in_sends_no_notice(): void
    {
        Notification::fake();
        $sdcAccount = User::factory()->microsoft($this->sdc)->create();
        $this->personWith($this->moving, $sdcAccount);

        $this->signInMovingToSdc();

        $this->assertNull($this->moving->fresh()->person_id);
        $this->assertSame(2, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
        Notification::assertNothingSent();
    }

    public function test_a_linked_account_moving_to_a_free_company_stays_linked(): void
    {
        $third = User::factory()->microsoft(Company::factory()->create())->create();
        $person = $this->personWith($this->moving, $third);

        $this->signInMovingToSdc();

        $this->assertTrue($this->moving->fresh()->company->is($this->sdc));
        $this->assertSame($person->id, $this->moving->fresh()->person_id);
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
    }

    public function test_admit_unlinks_as_well(): void
    {
        $sdcAccount = User::factory()->microsoft($this->sdc)->create();
        $this->personWith($this->moving, $sdcAccount);

        $admitted = $this->app->make(MicrosoftLogin::class)->admit($this->identity())['user'];

        $this->assertTrue($admitted->is($this->moving));
        $this->assertNull($admitted->person_id);
        $this->assertTrue($this->moving->fresh()->company->is($this->sdc));
        // The moving account and the last account of the dissolved person.
        $this->assertSame(2, Activity::query()->where('event', SecurityLog::ACCOUNT_UNLINKED)->count());
        $this->assertSame(0, Activity::query()->where('event', SecurityLog::MICROSOFT)->count());
    }
}
