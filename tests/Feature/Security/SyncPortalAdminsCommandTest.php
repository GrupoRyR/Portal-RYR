<?php

namespace Tests\Feature\Security;

use App\Audit\AuditPresenter;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SyncPortalAdminsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const ADMIN_GROUP = 'aaaaaaaa-0000-0000-0000-000000000001';

    private Company $segurex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->segurex = Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])->create();
        config(['portal.admin_group_id' => self::ADMIN_GROUP, 'portal.admin_tenant_id' => self::SEGUREX_TENANT]);
    }

    /**
     * @param  list<array{id: string, display_name: string}>  $groups
     */
    private function portalAdmin(Company $company, array $groups): User
    {
        $user = User::factory()->microsoft($company)->create();
        $user->syncGroups($groups);
        $user->assignRole('portal_admin');

        return $user;
    }

    public function test_it_revokes_roles_granted_by_group_name_and_keeps_the_pinned_group(): void
    {
        $member = $this->portalAdmin($this->segurex, [['id' => self::ADMIN_GROUP, 'display_name' => 'APP-PortalAdmin']]);
        $byName = $this->portalAdmin($this->segurex, [['id' => 'cccccccc-0000-0000-0000-000000000001', 'display_name' => 'APP-PortalAdmin']]);
        $otherTenant = $this->portalAdmin(Company::factory()->create(), [['id' => 'cccccccc-0000-0000-0000-000000000002', 'display_name' => 'APP-PortalAdmin']]);
        $byName->assignRole('announcements_editor');

        $this->artisan('portal:sync-portal-admins')
            ->expectsOutputToContain('2 quitados, 0 agregados.')
            ->assertSuccessful();

        $this->assertTrue($member->fresh()->hasRole('portal_admin'));
        $this->assertFalse($byName->fresh()->hasRole('portal_admin'));
        $this->assertTrue($byName->fresh()->hasRole('announcements_editor'));
        $this->assertFalse($otherTenant->fresh()->hasRole('portal_admin'));
    }

    public function test_it_grants_the_role_to_members_of_the_pinned_group(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create();
        $user->syncGroups([['id' => self::ADMIN_GROUP, 'display_name' => 'Administradores del portal']]);

        $this->artisan('portal:sync-portal-admins')
            ->expectsOutputToContain('0 quitados, 1 agregado.')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole('portal_admin'));
    }

    public function test_each_change_is_a_security_event_by_the_system(): void
    {
        $removed = $this->portalAdmin($this->segurex, [['id' => 'cccccccc-0000-0000-0000-000000000001', 'display_name' => 'APP-PortalAdmin']]);
        $added = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez', 'email' => 'ana@segurex.com']);
        $added->syncGroups([['id' => self::ADMIN_GROUP, 'display_name' => 'APP-PortalAdmin']]);
        $this->portalAdmin($this->segurex, [['id' => self::ADMIN_GROUP, 'display_name' => 'APP-PortalAdmin']]);

        $this->artisan('portal:sync-portal-admins')->assertSuccessful();

        $entries = Activity::query()->where('log_name', 'security')->where('event', 'roles.synced')->get()->keyBy('subject_id');
        $this->assertCount(2, $entries);
        $this->assertNull($entries[$added->id]->causer_id);
        $this->assertSame(['portal_admin'], $entries[$added->id]->properties['roles_added']);
        $this->assertSame([], $entries[$added->id]->properties['roles_removed']);
        $this->assertSame(['portal_admin'], $entries[$removed->id]->properties['roles_removed']);

        $entry = app(AuditPresenter::class)->activity($entries[$added->id]);
        $this->assertSame(AuditPresenter::SYSTEM, $entry->who);
        $this->assertStringContainsString('Ana Pérez', $entry->what);
        $this->assertStringContainsString('ahora es Administrador', $entry->what);
    }

    public function test_without_a_pinned_group_it_changes_nothing_and_fails(): void
    {
        config(['portal.admin_group_id' => null]);
        $user = $this->portalAdmin($this->segurex, [['id' => self::ADMIN_GROUP, 'display_name' => 'APP-PortalAdmin']]);

        $this->artisan('portal:sync-portal-admins')
            ->expectsOutputToContain('PORTAL_ADMIN_GROUP_ID')
            ->assertFailed();

        $this->assertTrue($user->fresh()->hasRole('portal_admin'));
    }
}
