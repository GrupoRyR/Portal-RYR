<?php

namespace Tests\Feature\Admin;

use App\Auth\RecentMicrosoftLogin;
use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyChangedNotice;
use App\Tenancy\TenantDirectory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompanyTenantChangeTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TENANT = '54f53029-e6e2-4b5f-a862-3454ae886468';

    private const NEW_TENANT = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    private Company $company;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();

        $this->segurex = Company::factory()->forTenant('97cd024c-cd7a-419b-b385-f47b5f5816c8', ['segurex.com'])->create(['slug' => 'segurex']);
        $this->company = Company::factory()->forTenant(self::OLD_TENANT, ['derivadoscol.com'])->create([
            'name' => 'Soluciones y Derivados',
            'short_name' => 'Derivados',
            'slug' => 'derivados',
            'consent_verified_at' => now(),
        ]);

        $this->admin = User::factory()->microsoft($this->segurex)->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(bool $fresh = true, ?Company $company = null): Testable
    {
        if ($fresh) {
            session()->put(RecentMicrosoftLogin::SESSION_KEY, ['user_id' => $this->admin->id, 'at' => now()->getTimestamp()]);
        }

        return Livewire::actingAs($this->admin)->test(CompanyEditor::class, ['company' => $company ?? $this->company]);
    }

    public function test_changing_the_tenant_needs_a_recent_microsoft_login(): void
    {
        $this->editor(fresh: false)
            ->call('startTenantChange')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/empresas/derivados']));

        $this->editor(fresh: false)
            ->set('newTenantId', self::NEW_TENANT)
            ->set('tenantConfirmation', 'Derivados')
            ->call('changeTenant')
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/empresas/derivados']));

        $this->assertSame(self::OLD_TENANT, $this->company->fresh()->tenant_id);
    }

    public function test_the_change_panel_warns_about_losing_access(): void
    {
        $this->editor()
            ->call('startTenantChange')
            ->assertSet('changingTenant', true)
            ->assertSee('Los usuarios del inquilino actual pierden el acceso de inmediato')
            ->assertSee('Escribe Derivados para confirmar');
    }

    public function test_emergency_accounts_cannot_change_tenants(): void
    {
        $emergency = User::factory()->emergency()->create();
        $emergency->assignRole('super_admin');

        Livewire::actingAs($emergency)
            ->test(CompanyEditor::class, ['company' => $this->company])
            ->call('startTenantChange')
            ->assertForbidden();
    }

    public function test_the_new_tenant_and_the_confirmation_are_validated(): void
    {
        $this->editor()->call('startTenantChange')
            ->set('newTenantId', 'no-es-guid')->set('tenantConfirmation', 'Derivados')
            ->call('changeTenant')->assertHasErrors(['newTenantId']);

        $this->editor()->call('startTenantChange')
            ->set('newTenantId', self::OLD_TENANT)->set('tenantConfirmation', 'Derivados')
            ->call('changeTenant')->assertHasErrors(['newTenantId']);

        $this->editor()->call('startTenantChange')
            ->set('newTenantId', self::NEW_TENANT)->set('tenantConfirmation', 'derivados s.a.')
            ->call('changeTenant')->assertHasErrors(['tenantConfirmation']);

        $this->assertSame(self::OLD_TENANT, $this->company->fresh()->tenant_id);
    }

    public function test_the_primary_rule_is_respected(): void
    {
        $this->editor()->call('startTenantChange')
            ->set('newTenantId', '97cd024c-cd7a-419b-b385-f47b5f5816c8')->set('tenantConfirmation', 'Derivados')
            ->call('changeTenant')
            ->assertHasErrors(['newTenantId']);

        $this->assertSame(self::OLD_TENANT, $this->company->fresh()->tenant_id);
    }

    public function test_the_admin_cannot_move_their_own_company(): void
    {
        $this->editor(company: $this->segurex)->call('startTenantChange')
            ->set('newTenantId', self::NEW_TENANT)->set('tenantConfirmation', $this->segurex->short_name)
            ->call('changeTenant')
            ->assertHasErrors(['newTenantId']);
    }

    public function test_a_confirmed_change_resets_consent_and_returns_to_the_wizard(): void
    {
        Cache::forever(TenantDirectory::CACHE_KEY, ['stale']);

        $this->editor()->call('startTenantChange')
            ->set('newTenantId', strtoupper(self::NEW_TENANT))
            ->set('tenantConfirmation', ' Derivados ')
            ->call('changeTenant')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.companies.wizard', 'derivados'));

        $company = $this->company->fresh();
        $this->assertSame(self::NEW_TENANT, $company->tenant_id);
        $this->assertNull($company->consent_verified_at);
        $this->assertFalse($company->is_active);
        $this->assertFalse(Cache::has(TenantDirectory::CACHE_KEY));

        $activity = Activity::where('event', 'company.tenant_changed')->sole();
        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertSame(self::OLD_TENANT, $activity->properties['old']['tenant_id']);

        Notification::assertSentTo($this->admin, CompanyChangedNotice::class, fn (CompanyChangedNotice $notice) => $notice->event === CompanyChangedNotice::TENANT_CHANGED);
    }

    public function test_reactivating_in_the_editor_needs_consent_and_notifies_admins(): void
    {
        $this->company->update(['is_active' => false, 'consent_verified_at' => null]);

        $this->editor()->set('isActive', true)->call('save')->assertHasErrors(['isActive']);
        $this->assertFalse($this->company->fresh()->is_active);
        Notification::assertNothingSent();

        $this->company->update(['consent_verified_at' => now()]);

        $this->editor(company: $this->company->fresh())->set('isActive', true)->call('save')->assertHasNoErrors();
        $this->assertTrue($this->company->fresh()->is_active);
        Notification::assertSentTo($this->admin, CompanyChangedNotice::class, fn (CompanyChangedNotice $notice) => $notice->event === CompanyChangedNotice::ACTIVATED);
    }
}
