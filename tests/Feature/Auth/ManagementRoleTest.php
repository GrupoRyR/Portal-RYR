<?php

namespace Tests\Feature\Auth;

use App\Auth\ManagementGroup;
use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The `management` role («Gerencia»): granted or removed at every Microsoft
 * sign-in from the security group whose object ID the super admin stored
 * on the account's company (`companies.management_group_id`), only within
 * that company's own tenant. Never by group name.
 */
class ManagementRoleTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const OID = '11111111-1111-1111-1111-111111111111';

    private const SEGUREX_GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const DERIVADOS_GROUP = 'bbbbbbbb-0000-4000-8000-000000000002';

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        Http::preventStrayRequests();

        $this->segurex = Company::factory()
            ->forTenant(self::SEGUREX_TENANT, ['segurex.com'])
            ->withManagementGroup(self::SEGUREX_GROUP)
            ->create();
        $this->derivados = Company::factory()
            ->forTenant(self::DERIVADOS_TENANT, ['derivados.com'])
            ->withManagementGroup(self::DERIVADOS_GROUP)
            ->create();
    }

    private function signInAs(string $tenantId, string $email = 'ana@segurex.com'): void
    {
        $raw = [
            'id' => self::OID,
            'displayName' => 'Ana Pérez',
            'jobTitle' => null,
            'mail' => $email,
            'userPrincipalName' => $email,
        ];

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
        $provider->shouldReceive('getClaims')->andReturn((object) ['tid' => $tenantId, 'oid' => self::OID]);

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    /**
     * @param  list<array<string, mixed>>  ...$pages  One list of groups per sign-in.
     */
    private function fakeGroups(array ...$pages): void
    {
        $sequence = Http::sequence();

        foreach ($pages as $groups) {
            $sequence->push(['value' => $groups]);
        }

        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => $sequence,
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Ana Pérez']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function securityGroup(string $id, string $name = 'APP-PortalGerencia'): array
    {
        return ['id' => $id, 'displayName' => $name, 'securityEnabled' => true, 'groupTypes' => []];
    }

    private function hitCallback(): TestResponse
    {
        return $this->get('/auth/microsoft/callback?code=abc&state=xyz');
    }

    public function test_a_member_of_the_company_group_gets_the_role_and_loses_it_when_removed(): void
    {
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([self::securityGroup(self::SEGUREX_GROUP)], []);

        $this->hitCallback()->assertRedirect('/');
        $user = User::sole();
        $user->assignRole('announcements_editor');
        $this->assertTrue($user->fresh()->hasRole(MicrosoftLogin::MANAGEMENT));

        $this->post('/logout');
        $this->hitCallback()->assertRedirect('/');

        $user = $user->fresh();
        $this->assertFalse($user->hasRole(MicrosoftLogin::MANAGEMENT));
        $this->assertTrue($user->hasRole('announcements_editor'));
    }

    public function test_the_group_id_is_compared_without_case(): void
    {
        $this->segurex->update(['management_group_id' => strtoupper(self::SEGUREX_GROUP)]);
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([self::securityGroup(strtoupper(self::SEGUREX_GROUP))]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertSame(self::SEGUREX_GROUP, $this->segurex->fresh()->management_group_id);
        $this->assertTrue(User::sole()->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    /**
     * Anyone who can create a group (a Team, say) can give it any name.
     */
    public function test_a_group_with_the_name_but_another_id_grants_nothing(): void
    {
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([self::securityGroup('cccccccc-0000-4000-8000-000000000001')]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    /**
     * Microsoft 365 groups (every Team) can be created by any user, and a
     * group that is not security enabled is not managed by IT.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nonSecurityGroups(): array
    {
        return [
            'microsoft 365 group' => [['id' => self::SEGUREX_GROUP, 'displayName' => 'APP-PortalGerencia', 'securityEnabled' => true, 'groupTypes' => ['Unified']]],
            'distribution group' => [['id' => self::SEGUREX_GROUP, 'displayName' => 'APP-PortalGerencia', 'securityEnabled' => false, 'groupTypes' => []]],
            'type unknown' => [['id' => self::SEGUREX_GROUP, 'displayName' => 'APP-PortalGerencia']],
        ];
    }

    #[DataProvider('nonSecurityGroups')]
    public function test_a_group_that_is_not_a_security_group_grants_nothing(array $group): void
    {
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([$group]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    /**
     * Groups are read from the tenant the user signed in to: the Segurex
     * group's ID means nothing in the Derivados tenant.
     */
    public function test_the_group_of_another_company_grants_nothing(): void
    {
        $this->signInAs(self::DERIVADOS_TENANT, 'luis@derivados.com');
        $this->fakeGroups([self::securityGroup(self::SEGUREX_GROUP)]);

        $this->hitCallback()->assertRedirect('/');

        $user = User::sole();
        $this->assertTrue($user->company->is($this->derivados));
        $this->assertFalse($user->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    public function test_the_group_of_another_company_in_the_same_tenant_grants_nothing(): void
    {
        $partner = Company::factory()
            ->forTenant(self::SEGUREX_TENANT, ['socio.com'], primary: false)
            ->withManagementGroup('bbbbbbbb-0000-4000-8000-000000000003')
            ->create();
        $this->signInAs(self::SEGUREX_TENANT, 'ana@socio.com');
        $this->fakeGroups([self::securityGroup(self::SEGUREX_GROUP)]);

        $this->hitCallback()->assertRedirect('/');

        $user = User::sole();
        $this->assertTrue($user->company->is($partner));
        $this->assertFalse($user->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    public function test_without_a_group_id_nobody_has_the_role(): void
    {
        $this->segurex->update(['management_group_id' => null]);
        $user = User::factory()->microsoft($this->segurex)->create(['email' => 'ana@segurex.com', 'azure_oid' => self::OID]);
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([self::securityGroup(self::SEGUREX_GROUP)]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse($user->fresh()->hasRole(MicrosoftLogin::MANAGEMENT));
    }

    public function test_admin_roles_survive_when_management_comes_and_goes(): void
    {
        $adminGroup = 'aaaaaaaa-0000-0000-0000-000000000001';
        config([
            'portal.super_admin_emails' => ['ana@segurex.com'],
            'portal.admin_group_id' => $adminGroup,
            'portal.admin_tenant_id' => self::SEGUREX_TENANT,
        ]);
        $this->signInAs(self::SEGUREX_TENANT);
        $both = [self::securityGroup($adminGroup, 'APP-PortalAdmin'), self::securityGroup(self::SEGUREX_GROUP)];
        $this->fakeGroups($both, [self::securityGroup($adminGroup, 'APP-PortalAdmin')], $both);

        $roles = function (): array {
            $names = User::sole()->getRoleNames()->all();
            sort($names);

            return $names;
        };

        $this->hitCallback()->assertRedirect('/');
        $this->assertSame([MicrosoftLogin::MANAGEMENT, MicrosoftLogin::PORTAL_ADMIN, MicrosoftLogin::SUPER_ADMIN], $roles());

        $this->post('/logout');
        $this->hitCallback()->assertRedirect('/');
        $this->assertSame([MicrosoftLogin::PORTAL_ADMIN, MicrosoftLogin::SUPER_ADMIN], $roles());

        $this->post('/logout');
        $this->hitCallback()->assertRedirect('/');
        $this->assertSame([MicrosoftLogin::MANAGEMENT, MicrosoftLogin::PORTAL_ADMIN, MicrosoftLogin::SUPER_ADMIN], $roles());
    }

    public function test_the_sign_in_entry_names_the_role_change(): void
    {
        $this->signInAs(self::SEGUREX_TENANT);
        $this->fakeGroups([self::securityGroup(self::SEGUREX_GROUP)]);

        $this->hitCallback()->assertRedirect('/');

        $entry = Activity::query()->where('event', 'login.microsoft')->sole();
        $this->assertSame([MicrosoftLogin::MANAGEMENT], $entry->properties['roles_added']);
    }

    public function test_the_stored_membership_is_checked_against_the_current_group(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create(['azure_oid' => self::OID]);
        $user->syncGroups([['id' => self::SEGUREX_GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        $this->assertTrue(ManagementGroup::contains($user));

        // Another company's group, or none, never counts.
        $this->assertFalse(ManagementGroup::contains($user, $this->derivados));
        $this->segurex->update(['management_group_id' => 'bbbbbbbb-0000-4000-8000-000000000009']);
        $this->assertFalse(ManagementGroup::contains($user->fresh()));
        $this->segurex->update(['management_group_id' => null]);
        $this->assertFalse(ManagementGroup::contains($user->fresh()));
    }

    public function test_emergency_accounts_never_count(): void
    {
        $user = User::factory()->emergency()->create(['company_id' => $this->segurex->id, 'tenant_id' => self::SEGUREX_TENANT]);
        $user->syncGroups([['id' => self::SEGUREX_GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        $this->assertFalse(ManagementGroup::contains($user));
    }
}
