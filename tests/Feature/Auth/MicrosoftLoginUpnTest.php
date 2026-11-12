<?php

namespace Tests\Feature\Auth;

use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Tests\TestCase;

/**
 * Each Microsoft sign-in keeps the account's own sign-in name (UPN, lower
 * case) in `users.upn`: the identity the agent matches (docs/agente-conexion.md).
 * It never changes how `users.email` is chosen. A guest UPN (`#EXT#`) is
 * never kept, and emergency accounts have none.
 */
class MicrosoftLoginUpnTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const OID = '11111111-1111-1111-1111-111111111111';

    private const OTHER_OID = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::response(['value' => []]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Ana Pérez', 'jobTitle' => null, 'mail' => 'ana@segurex.com']),
        ]);

        Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])->create();
        Company::factory()->forTenant(self::DERIVADOS_TENANT, ['derivados.com'])->create();
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function socialiteUser(array $claims, array $profile = []): MicrosoftUser
    {
        $raw = array_merge([
            'id' => $claims['oid'] ?? self::OID,
            'displayName' => 'Ana Pérez',
            'jobTitle' => null,
            'mail' => 'Ana.Perez@Segurex.com',
            'userPrincipalName' => 'AEPerez@Segurex.com',
        ], $profile);

        $user = (new MicrosoftUser)->setRaw($raw)->map([
            'id' => $raw['id'],
            'name' => $raw['displayName'],
            'email' => $raw['userPrincipalName'] ?? null,
            'mail' => $raw['mail'] ?? null,
            'userPrincipalName' => $raw['userPrincipalName'] ?? null,
        ]);
        $user->setToken('graph-access-token');

        return $user;
    }

    /**
     * The identity the Portal reads from the sign-in, as the controllers do.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function identity(array $claims, array $profile = []): MicrosoftIdentity
    {
        return MicrosoftIdentity::fromSocialite($this->socialiteUser($claims, $profile), (object) $claims);
    }

    /**
     * Admit the account the way a sign-in does, once more.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function admit(array $claims, array $profile = []): User
    {
        return $this->app->make(MicrosoftLogin::class)->admit($this->identity($claims, $profile))['user'];
    }

    public function test_a_real_sign_in_stores_the_sign_in_name_in_lower_case_apart_from_the_email(): void
    {
        $claims = ['tid' => self::SEGUREX_TENANT, 'oid' => self::OID];
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andReturn($this->socialiteUser($claims));
        $provider->shouldReceive('getClaims')->andReturn((object) $claims);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get('/auth/microsoft/callback?code=abc&state=xyz')->assertRedirect('/');

        $user = User::sole();
        $this->assertSame('aeperez@segurex.com', $user->upn);
        // How the email is chosen is untouched: the mail attribute first.
        $this->assertSame('ana.perez@segurex.com', $user->email);
    }

    public function test_it_is_updated_when_it_changes_at_the_next_sign_in(): void
    {
        $this->admit(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->assertSame('aeperez@segurex.com', User::sole()->upn);

        $this->admit(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID], ['userPrincipalName' => 'ana.perez@segurex.com']);

        $this->assertSame(1, User::count());
        $this->assertSame('ana.perez@segurex.com', User::sole()->upn);
    }

    public function test_the_upn_claim_is_used_when_the_profile_has_none(): void
    {
        $identity = $this->identity(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID, 'upn' => 'Claim.User@Segurex.com'], ['userPrincipalName' => null]);

        $this->assertSame('claim.user@segurex.com', $identity->upn);
    }

    public function test_the_profile_wins_over_the_claim_and_the_other_claims_are_not_taken_for_a_upn(): void
    {
        $both = $this->identity(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID, 'upn' => 'claim@segurex.com', 'preferred_username' => 'pref@segurex.com', 'email' => 'mailclaim@segurex.com']);
        $this->assertSame('aeperez@segurex.com', $both->upn);

        // preferred_username and email are not guaranteed to be a UPN.
        $neither = $this->identity(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::OID, 'preferred_username' => 'pref@segurex.com', 'email' => 'mailclaim@segurex.com'],
            ['userPrincipalName' => null, 'mail' => 'other@segurex.com'],
        );
        $this->assertNull($neither->upn);
        $this->assertSame('other@segurex.com', $neither->email, 'the email is still chosen as before');
    }

    public function test_a_guest_upn_is_never_stored_and_clears_what_was_there(): void
    {
        $this->admit(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->assertNotNull(User::sole()->upn);

        // The profile's guest UPN decides: it is not replaced by a later candidate.
        $this->admit(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::OID, 'upn' => 'someone@segurex.com'],
            ['userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );

        $this->assertNull(User::sole()->upn);
    }

    public function test_it_is_lowercased_with_unicode_rules_like_the_database_does(): void
    {
        $identity = $this->identity(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID], ['userPrincipalName' => 'JOSÉ.Núñez@Segurex.com']);

        $this->assertSame('josé.núñez@segurex.com', $identity->upn);
    }

    public function test_a_value_that_is_not_an_address_is_not_stored(): void
    {
        $values = ['no-at-sign', 'a b@segurex.com', 'a@@segurex.com', "a@seg\x01urex.com", str_repeat('a', 250).'@segurex.com'];

        foreach ($values as $i => $value) {
            $identity = $this->identity(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID], ['userPrincipalName' => $value]);

            $this->assertNull($identity->upn, 'case '.$i);
        }

        foreach (['', '   '] as $blank) {
            $this->assertNull($this->identity(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID], ['userPrincipalName' => $blank])->upn);
        }
    }

    public function test_each_account_of_a_person_keeps_its_own_upn(): void
    {
        $this->admit(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->admit(
            ['tid' => self::DERIVADOS_TENANT, 'oid' => self::OTHER_OID],
            ['mail' => 'Ana.Perez@Derivados.com', 'userPrincipalName' => 'ana.perez@derivados.com'],
        );

        $this->assertSame(
            ['aeperez@segurex.com', 'ana.perez@derivados.com'],
            User::query()->orderBy('id')->pluck('upn')->all(),
        );
    }

    public function test_emergency_accounts_never_have_one(): void
    {
        $this->assertNull(User::factory()->emergency()->create()->upn);
    }

    public function test_it_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->microsoft()->create();
        $user->fill(['upn' => 'someone@segurex.com']);

        $this->assertNotSame('someone@segurex.com', $user->upn);
    }
}
