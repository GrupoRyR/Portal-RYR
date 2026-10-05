<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthSetupTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['super_admin', 'portal_admin', 'announcements_editor'];

    public function test_microsoft_signs_in_through_the_organizations_endpoint_by_default(): void
    {
        $this->assertSame('organizations', config('services.microsoft.tenant'));
        $this->assertArrayHasKey('client_id', config('services.microsoft'));
        $this->assertArrayHasKey('client_secret', config('services.microsoft'));
        $this->assertArrayHasKey('redirect', config('services.microsoft'));
    }

    public function test_the_microsoft_socialite_driver_is_registered(): void
    {
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
            'services.microsoft.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        $this->assertInstanceOf(MicrosoftProvider::class, Socialite::driver('microsoft'));
    }

    public function test_portal_admin_group_is_configured(): void
    {
        $this->assertSame('APP-PortalAdmin', config('portal.admin_group'));
        $this->assertIsArray(config('portal.super_admin_emails'));
    }

    public function test_permission_teams_are_disabled(): void
    {
        $this->assertFalse(config('permission.teams'));
    }

    public function test_role_seeder_creates_the_portal_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(self::ROLES, Role::pluck('name')->all());
        $this->assertSame(['web'], Role::distinct()->pluck('guard_name')->all());
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count());
    }

    public function test_database_seeder_seeds_the_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(self::ROLES, Role::pluck('name')->all());
    }

    public function test_users_can_hold_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();

        $user->assignRole('portal_admin');

        $this->assertTrue($user->fresh()->hasRole('portal_admin'));
    }
}
