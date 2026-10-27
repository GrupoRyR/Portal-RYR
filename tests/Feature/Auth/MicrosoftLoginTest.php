<?php

namespace Tests\Feature\Auth;

use App\Auth\RecentMicrosoftLogin;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MicrosoftLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const OID = '11111111-1111-1111-1111-111111111111';

    private const GUEST_OID = '22222222-2222-2222-2222-222222222222';

    /**
     * `idp` claim of a B2B guest whose home tenant is Derivados.
     */
    private const DERIVADOS_IDP = 'https://sts.windows.net/54f53029-e6e2-4b5f-a862-3454ae886468/';

    private const ADMIN_GROUP = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const TENANT_MESSAGE = 'Tu organización no tiene acceso a este portal.';

    private const FAILURE_MESSAGE = 'No pudimos completar el inicio de sesión. Inténtalo de nuevo.';

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        Http::preventStrayRequests();

        $this->segurex = Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])->create();
        $this->derivados = Company::factory()->forTenant(self::DERIVADOS_TENANT, ['derivados.com'])->create();
    }

    /**
     * Make Socialite return a Microsoft user whose validated id_token holds
     * the given claims.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $profile
     */
    private function signInAs(array $claims, array $profile = []): void
    {
        $raw = array_merge([
            'id' => $claims['oid'] ?? self::OID,
            'displayName' => 'Ana Pérez',
            'jobTitle' => null,
            'mail' => 'Ana@Segurex.com',
            'userPrincipalName' => 'ana@segurex.com',
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

    /**
     * @param  list<array{id: string, displayName: string}>  $groups
     */
    private function fakeGraph(
        array $groups = [],
        string $displayName = 'Ana Pérez',
        ?string $jobTitle = 'Analista',
        int $photoStatus = 200,
    ): void {
        Http::fake([
            'graph.microsoft.com/v1.0/me/photo/*' => $photoStatus === 200
                ? Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])
                : Http::response(['error' => ['code' => 'ImageNotFound']], $photoStatus),
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::response(['value' => $groups]),
            'graph.microsoft.com/v1.0/me*' => Http::response([
                'displayName' => $displayName,
                'jobTitle' => $jobTitle,
                'mail' => 'ana@segurex.com',
            ]),
        ]);
    }

    private function hitCallback(): TestResponse
    {
        return $this->get('/auth/microsoft/callback?code=abc&state=xyz');
    }

    public function test_an_allowed_tenant_signs_in_and_lands_in_its_company(): void
    {
        $this->signInAs(['tid' => strtoupper(self::SEGUREX_TENANT), 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ana@segurex.com', $user->email);
        $this->assertSame(self::SEGUREX_TENANT, $user->tenant_id);
        $this->assertSame(self::OID, $user->azure_oid);
        $this->assertTrue($user->company->is($this->segurex));
        $this->assertNull($user->password);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_a_derivados_user_lands_in_derivados(): void
    {
        $this->signInAs(
            ['tid' => self::DERIVADOS_TENANT, 'oid' => self::OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis@derivados.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertTrue(User::sole()->company->is($this->derivados));
    }

    public function test_the_session_is_regenerated_and_the_intended_url_is_honoured(): void
    {
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->withSession(['url.intended' => url('/apps')])
            ->hitCallback()
            ->assertRedirect(url('/apps'));
    }

    public function test_a_normal_sign_in_counts_as_a_recent_microsoft_confirmation(): void
    {
        $this->freezeTime();
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback()
            ->assertRedirect('/')
            ->assertSessionHas(RecentMicrosoftLogin::SESSION_KEY, now()->getTimestamp());
    }

    public function test_a_rejected_sign_in_does_not_count_as_a_recent_confirmation(): void
    {
        $this->signInAs(['tid' => '11111111-1111-1111-1111-111111111111', 'oid' => self::OID]);

        $this->hitCallback()->assertSessionMissing(RecentMicrosoftLogin::SESSION_KEY);
    }

    public function test_an_unknown_tenant_is_rejected(): void
    {
        $this->signInAs(['tid' => 'ffffffff-0000-0000-0000-000000000000', 'oid' => self::OID]);

        $this->hitCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::TENANT_MESSAGE]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_tenant_whose_company_is_inactive_is_rejected(): void
    {
        $this->derivados->update(['is_active' => false]);
        $this->signInAs(
            ['tid' => self::DERIVADOS_TENANT, 'oid' => self::OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis@derivados.com'],
        );

        $this->hitCallback()->assertSessionHasErrors(['login' => self::TENANT_MESSAGE]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_sign_in_through_an_inactive_company_tenant_is_rejected(): void
    {
        $this->segurex->update(['is_active' => false]);
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID, 'idp' => self::DERIVADOS_IDP],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );

        $this->hitCallback()->assertSessionHasErrors(['login' => self::TENANT_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_derivados_guest_in_segurex_becomes_a_separate_segurex_user(): void
    {
        $home = User::factory()->microsoft($this->derivados)->create([
            'name' => 'Luis Gómez',
            'email' => 'luis@derivados.com',
            'azure_oid' => self::OID,
            'job_title' => 'Contador',
        ]);
        $home->syncGroups([['id' => self::ADMIN_GROUP, 'display_name' => 'APP-PortalAdmin']]);
        $home->assignRole('portal_admin');
        $before = $home->fresh()->getAttributes();
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID, 'idp' => self::DERIVADOS_IDP],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );
        $this->fakeGraph(groups: [['id' => 'bbbbbbbb-0000-0000-0000-000000000009', 'displayName' => 'APP-Cartera']]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertDatabaseCount('users', 2);
        $guest = User::where('azure_oid', self::GUEST_OID)->sole();
        $this->assertAuthenticatedAs($guest);
        $this->assertSame('luis@derivados.com', $guest->email);
        $this->assertSame(self::SEGUREX_TENANT, $guest->tenant_id);
        $this->assertTrue($guest->company->is($this->segurex));
        $this->assertSame(['APP-Cartera'], $guest->groups()->pluck('display_name')->all());
        $this->assertFalse($guest->hasRole('portal_admin'));

        $home->refresh();
        $this->assertSame($before, $home->getAttributes());
        $this->assertSame(['APP-PortalAdmin'], $home->groups()->pluck('display_name')->all());
        $this->assertSame(['portal_admin'], $home->getRoleNames()->all());
    }

    /**
     * Someone signs in with `mail` set to the super admin's address: an
     * admin of another allowed tenant, or a guest inside the owning tenant.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function spoofedMailClaims(): array
    {
        return [
            'home user, no idp' => [['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID]],
            'idp is the signing tenant' => [['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID, 'idp' => self::DERIVADOS_IDP]],
            'idp of a third tenant' => [['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID, 'idp' => 'https://sts.windows.net/cccccccc-0000-0000-0000-000000000000/']],
            'unparseable idp' => [['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID, 'idp' => 'live.com']],
            'guest in the owning tenant' => [['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID, 'idp' => self::DERIVADOS_IDP]],
            'guest in the owning tenant, foreign idp' => [['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID, 'idp' => 'https://sts.windows.net/cccccccc-0000-0000-0000-000000000000/']],
        ];
    }

    #[DataProvider('spoofedMailClaims')]
    public function test_a_spoofed_mail_cannot_touch_another_account(array $claims): void
    {
        config(['portal.super_admin_emails' => ['jhon.castro@segurex.com']]);
        $jhon = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
            'azure_oid' => self::OID,
        ]);
        $jhon->assignRole('super_admin');
        $before = $jhon->fresh()->getAttributes();
        $this->signInAs($claims, [
            'mail' => 'jhon.castro@segurex.com',
            'userPrincipalName' => 'mallory@derivados.com',
        ]);
        $this->fakeGraph(displayName: 'Mallory');

        $this->hitCallback()->assertRedirect('/');

        $this->assertDatabaseCount('users', 2);
        $mallory = User::where('azure_oid', self::GUEST_OID)->sole();
        $this->assertAuthenticatedAs($mallory);
        $this->assertFalse($mallory->hasRole('super_admin'));
        $this->assertSame($before, $jhon->fresh()->getAttributes());
        $this->assertSame(['super_admin'], $jhon->fresh()->getRoleNames()->all());
    }

    public function test_super_admin_is_granted_on_a_home_sign_in_with_an_issuer_style_idp(): void
    {
        config(['portal.super_admin_emails' => ['ana@segurex.com']]);
        $this->signInAs([
            'tid' => self::SEGUREX_TENANT,
            'oid' => self::OID,
            'idp' => 'https://login.microsoftonline.com/'.strtoupper(self::SEGUREX_TENANT).'/v2.0',
        ]);
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertTrue(User::sole()->hasRole('super_admin'));
    }

    public function test_super_admin_is_not_granted_on_a_guest_sign_in(): void
    {
        config(['portal.super_admin_emails' => ['luis@derivados.com']]);
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID, 'idp' => self::DERIVADOS_IDP],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole('super_admin'));
    }

    public function test_super_admin_is_not_granted_when_no_company_owns_the_email_domain(): void
    {
        config(['portal.super_admin_emails' => ['ana@gmail.com']]);
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::OID],
            ['mail' => 'ana@gmail.com', 'userPrincipalName' => 'ana@segurex.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole('super_admin'));
    }

    public function test_repeated_sign_ins_of_the_same_identity_update_the_same_row(): void
    {
        $existing = User::factory()->microsoft($this->segurex)->create([
            'email' => 'old@segurex.com',
            'azure_oid' => self::OID,
        ]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();
        $this->post('/logout');
        $this->hitCallback();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($existing);
        $this->assertSame('ana@segurex.com', $existing->fresh()->email);
    }

    public function test_an_existing_user_with_the_same_email_is_never_linked(): void
    {
        $existing = User::factory()->create(['email' => 'ana@segurex.com']);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertDatabaseCount('users', 2);
        $this->assertNull($existing->fresh()->azure_oid);
        $user = User::where('azure_oid', self::OID)->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ana@segurex.com', $user->email);
    }

    public function test_an_email_owned_by_no_company_is_stored_even_if_another_user_has_it(): void
    {
        $existing = User::factory()->microsoft($this->segurex)->create(['email' => 'luis@gmail.com']);
        $this->signInAs(
            ['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => 'luis@gmail.com', 'userPrincipalName' => 'luis@derivados.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertDatabaseCount('users', 2);
        $this->assertNotSame(self::GUEST_OID, $existing->fresh()->azure_oid);
        $user = User::where('azure_oid', self::GUEST_OID)->sole();
        $this->assertSame('luis@gmail.com', $user->email);
        $this->assertTrue($user->company->is($this->derivados));
    }

    public function test_profile_photo_and_groups_are_updated_from_graph(): void
    {
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph(
            groups: [['id' => self::ADMIN_GROUP, 'displayName' => 'APP-TomaPedidos']],
            displayName: 'Ana María Pérez',
            jobTitle: 'Jefe de Ventas',
        );

        $this->hitCallback();

        $user = User::sole();
        $this->assertSame('Ana María Pérez', $user->name);
        $this->assertSame('Jefe de Ventas', $user->job_title);
        $this->assertSame(['APP-TomaPedidos'], $user->groups()->pluck('display_name')->all());
        $this->assertSame("avatars/{$user->id}.jpg", $user->photo_path);
        Storage::disk('public')->assertExists("avatars/{$user->id}.jpg");
        $this->assertSame('jpeg-bytes', Storage::disk('public')->get("avatars/{$user->id}.jpg"));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer graph-access-token'));
    }

    public function test_a_missing_photo_keeps_the_previous_one(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create([
            'email' => 'ana@segurex.com',
            'azure_oid' => self::OID,
            'photo_path' => 'avatars/previous.jpg',
        ]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph(photoStatus: 404);

        $this->hitCallback()->assertRedirect('/');

        $this->assertSame('avatars/previous.jpg', $user->fresh()->photo_path);
    }

    public function test_a_graph_failure_does_not_block_sign_in_and_keeps_previous_data(): void
    {
        Log::spy();
        $user = User::factory()->microsoft($this->segurex)->create([
            'name' => 'Ana Anterior',
            'email' => 'ana@segurex.com',
            'azure_oid' => self::OID,
            'job_title' => 'Analista',
        ]);
        $user->syncGroups([['id' => self::ADMIN_GROUP, 'display_name' => 'APP-Cartera']]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        Http::fake(['graph.microsoft.com/*' => Http::response(['error' => 'boom'], 503)]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('Ana Anterior', $user->name);
        $this->assertSame('Analista', $user->job_title);
        $this->assertSame(['APP-Cartera'], $user->groups()->pluck('display_name')->all());
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * The portal admins group is pinned by object ID in the Segurex tenant.
     */
    private function pinAdminGroup(): void
    {
        config([
            'portal.admin_group_id' => strtoupper(self::ADMIN_GROUP),
            'portal.admin_tenant_id' => self::SEGUREX_TENANT,
        ]);
    }

    public function test_portal_admin_group_membership_grants_and_later_revokes_the_role(): void
    {
        $this->pinAdminGroup();
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        Http::fake([
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::sequence()
                ->push(['value' => [['id' => self::ADMIN_GROUP, 'displayName' => 'APP-PortalAdmin', 'securityEnabled' => true, 'groupTypes' => []]]])
                ->push(['value' => []]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['displayName' => 'Ana Pérez']),
        ]);

        $this->hitCallback();
        $user = User::sole();
        $user->assignRole('announcements_editor');
        $this->assertTrue($user->fresh()->hasRole('portal_admin'));

        $this->post('/logout');
        $this->hitCallback();

        $user = $user->fresh();
        $this->assertFalse($user->hasRole('portal_admin'));
        $this->assertTrue($user->hasRole('announcements_editor'));
    }

    /**
     * Anyone who can create a group (a Team, say) can give it any name.
     */
    public function test_a_group_with_the_admin_name_but_another_id_grants_nothing(): void
    {
        $this->pinAdminGroup();
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph(groups: [['id' => 'cccccccc-0000-0000-0000-000000000001', 'displayName' => 'APP-PortalAdmin']]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole('portal_admin'));
    }

    /**
     * Groups are read from the tenant the user signed in to, whose admins
     * decide what they contain: only the Segurex tenant is trusted.
     *
     * @return array<string, array{string}>
     */
    public static function foreignAdminGroups(): array
    {
        return [
            'same name' => ['cccccccc-0000-0000-0000-000000000002'],
            'same id' => [self::ADMIN_GROUP],
        ];
    }

    #[DataProvider('foreignAdminGroups')]
    public function test_the_admin_group_of_another_tenant_grants_nothing(string $groupId): void
    {
        $this->pinAdminGroup();
        $this->signInAs(
            ['tid' => self::DERIVADOS_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis@derivados.com'],
        );
        $this->fakeGraph(groups: [['id' => $groupId, 'displayName' => 'APP-PortalAdmin']]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse(User::sole()->hasRole('portal_admin'));
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function unpinnedAdminGroups(): array
    {
        return [
            'nothing configured' => [['portal.admin_group_id' => null, 'portal.admin_tenant_id' => null]],
            'no group' => [['portal.admin_group_id' => '', 'portal.admin_tenant_id' => self::SEGUREX_TENANT]],
            'no tenant' => [['portal.admin_group_id' => self::ADMIN_GROUP, 'portal.admin_tenant_id' => '']],
        ];
    }

    #[DataProvider('unpinnedAdminGroups')]
    public function test_without_a_pinned_group_nobody_is_portal_admin(array $settings): void
    {
        config($settings);
        $user = User::factory()->microsoft($this->segurex)->create(['email' => 'ana@segurex.com', 'azure_oid' => self::OID]);
        $user->assignRole('portal_admin');
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph(groups: [['id' => self::ADMIN_GROUP, 'displayName' => 'APP-PortalAdmin']]);

        $this->hitCallback()->assertRedirect('/');

        $this->assertFalse($user->fresh()->hasRole('portal_admin'));
    }

    public function test_configured_super_admin_emails_get_the_super_admin_role(): void
    {
        config(['portal.super_admin_emails' => ['ana@segurex.com']]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();

        $this->assertTrue(User::sole()->hasRole('super_admin'));
    }

    public function test_super_admin_is_removed_when_the_email_leaves_the_list(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create([
            'email' => 'ana@segurex.com',
            'azure_oid' => self::OID,
        ]);
        $user->assignRole('super_admin');
        config(['portal.super_admin_emails' => ['jhon@segurex.com']]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();

        $this->assertFalse($user->fresh()->hasRole('super_admin'));
    }

    public function test_an_ext_upn_is_never_used_as_email(): void
    {
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => null, 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertSessionHasErrors(['login' => self::FAILURE_MESSAGE]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'luis_derivados.com#ext#@segurex.onmicrosoft.com']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_upn_is_used_when_there_is_no_mail(): void
    {
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::OID],
            ['mail' => null, 'userPrincipalName' => 'Ana@Segurex.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertSame('ana@segurex.com', User::sole()->email);
    }

    public function test_a_socialite_error_shows_a_retry_message(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->hitCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => self::FAILURE_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_token_without_tenant_or_object_id_is_rejected(): void
    {
        $this->signInAs(['oid' => self::OID]);

        $this->hitCallback()->assertSessionHasErrors(['login' => self::FAILURE_MESSAGE]);

        $this->assertGuest();
    }

    // --- Security events (Auditoría) ---------------------------------------

    public function test_a_successful_sign_in_is_recorded_as_a_security_event(): void
    {
        config(['portal.super_admin_emails' => ['ana@segurex.com']]);
        $this->pinAdminGroup();
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph(groups: [['id' => self::ADMIN_GROUP, 'displayName' => 'APP-PortalAdmin']]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
            ->hitCallback()
            ->assertRedirect('/');

        $user = User::sole();
        $entry = Activity::query()->where('log_name', 'security')->sole();

        $this->assertSame('login.microsoft', $entry->event);
        $this->assertTrue($entry->causer->is($user));
        $this->assertSame($this->segurex->id, $entry->properties['company_id']);
        $this->assertSame($this->segurex->name, $entry->properties['company']);
        $this->assertSame('phone', $entry->properties['device']);
        $this->assertEqualsCanonicalizing(['super_admin', 'portal_admin'], $entry->properties['roles_added']);
        $this->assertSame([], $entry->properties['roles_removed']);
        $this->assertStringNotContainsString('graph-access-token', json_encode($entry->properties));
    }

    public function test_role_removals_at_sign_in_are_part_of_the_event(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create(['email' => 'ana@segurex.com', 'azure_oid' => self::OID]);
        $user->assignRole('portal_admin');
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame([], $entry->properties['roles_added']);
        $this->assertSame(['portal_admin'], $entry->properties['roles_removed']);
    }

    public function test_a_rejected_tenant_is_recorded_without_causer(): void
    {
        $this->signInAs(['tid' => 'FFFFFFFF-0000-0000-0000-000000000000', 'oid' => self::OID]);

        $this->hitCallback()->assertRedirect(route('login'));

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('login.microsoft_rejected', $entry->event);
        $this->assertNull($entry->causer_id);
        $this->assertSame('tenant_not_allowed', $entry->properties['reason']);
        $this->assertSame('ffffffff-0000-0000-0000-000000000000', $entry->properties['tenant_id']);
        $this->assertStringNotContainsString('graph-access-token', json_encode($entry->properties));
    }

    public function test_a_provider_error_is_recorded_without_a_tenant(): void
    {
        $provider = Mockery::mock(MicrosoftProvider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->hitCallback();

        $entry = Activity::query()->where('log_name', 'security')->sole();
        $this->assertSame('login.microsoft_rejected', $entry->event);
        $this->assertSame('provider_error', $entry->properties['reason']);
        $this->assertNull($entry->properties['tenant_id']);
    }
}
