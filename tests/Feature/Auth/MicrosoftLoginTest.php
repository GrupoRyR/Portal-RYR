<?php

namespace Tests\Feature\Auth;

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
use SocialiteProviders\Microsoft\MicrosoftUser;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Tests\TestCase;

class MicrosoftLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const DERIVADOS_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const OID = '11111111-1111-1111-1111-111111111111';

    private const GUEST_OID = '22222222-2222-2222-2222-222222222222';

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

    public function test_a_guest_of_an_inactive_company_is_rejected(): void
    {
        $this->derivados->update(['is_active' => false]);
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );

        $this->hitCallback()->assertSessionHasErrors(['login' => self::TENANT_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_derivados_guest_in_segurex_is_linked_to_the_same_user(): void
    {
        $home = User::factory()->microsoft($this->derivados)->create([
            'email' => 'luis@derivados.com',
            'azure_oid' => self::OID,
        ]);
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($home);
        $home->refresh();
        $this->assertTrue($home->company->is($this->derivados));
        $this->assertSame(self::DERIVADOS_TENANT, $home->tenant_id);
        $this->assertSame(self::OID, $home->azure_oid);
    }

    public function test_a_first_time_guest_gets_the_company_owning_its_email_domain(): void
    {
        $this->signInAs(
            ['tid' => self::SEGUREX_TENANT, 'oid' => self::GUEST_OID],
            ['mail' => 'luis@derivados.com', 'userPrincipalName' => 'luis_derivados.com#EXT#@segurex.onmicrosoft.com'],
        );
        $this->fakeGraph();

        $this->hitCallback()->assertRedirect('/');

        $user = User::sole();
        $this->assertSame('luis@derivados.com', $user->email);
        $this->assertTrue($user->company->is($this->derivados));
    }

    public function test_a_user_is_matched_by_object_id_before_email(): void
    {
        $existing = User::factory()->microsoft($this->segurex)->create([
            'email' => 'old@segurex.com',
            'azure_oid' => self::OID,
        ]);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($existing);
        $this->assertSame('ana@segurex.com', $existing->fresh()->email);
    }

    public function test_an_existing_user_without_identity_is_linked_by_email(): void
    {
        $existing = User::factory()->create(['email' => 'ana@segurex.com']);
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        $this->fakeGraph();

        $this->hitCallback();

        $existing->refresh();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(self::OID, $existing->azure_oid);
        $this->assertSame(self::SEGUREX_TENANT, $existing->tenant_id);
        $this->assertTrue($existing->company->is($this->segurex));
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
        $this->assertTrue($user->isInGroup('APP-TomaPedidos'));
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
        $this->assertTrue($user->isInGroup('APP-Cartera'));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_portal_admin_group_membership_grants_and_later_revokes_the_role(): void
    {
        $this->signInAs(['tid' => self::SEGUREX_TENANT, 'oid' => self::OID]);
        Http::fake([
            'graph.microsoft.com/v1.0/me/photo/*' => Http::response([], 404),
            'graph.microsoft.com/v1.0/me/transitiveMemberOf/*' => Http::sequence()
                ->push(['value' => [['id' => self::ADMIN_GROUP, 'displayName' => 'APP-PortalAdmin']]])
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
}
