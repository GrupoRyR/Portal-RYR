<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantDirectory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyEditorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'domains' => ['segurex.com'],
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
            'quick_links' => [['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com']],
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(CompanyEditor::class, ['company' => $this->company]);
    }

    public function test_it_loads_the_company_values(): void
    {
        $this->editor()
            ->assertSet('name', 'Segurex Latam')
            ->assertSet('shortName', 'Segurex')
            ->assertSet('domains', ['segurex.com'])
            ->assertSet('colorPrimary', '#FF671F')
            ->assertSet('quickLinks', [['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com']])
            ->assertSet('isActive', true);
    }

    public function test_only_super_admins_can_use_it(): void
    {
        foreach (['portal_admin', null] as $role) {
            $user = User::factory()->create();

            if ($role) {
                $user->assignRole($role);
            }

            Livewire::actingAs($user)->test(CompanyEditor::class, ['company' => $this->company])->assertForbidden();
        }
    }

    public function test_it_warns_when_white_text_on_the_primary_fails_contrast(): void
    {
        $this->editor()
            ->assertSee('El principal no sirve como fondo de botón')
            ->assertSee('2,9:1')
            ->assertSee('#C24C15')
            ->set('colorPrimary', '#183F94')
            ->assertDontSee('El principal no sirve como fondo de botón');
    }

    public function test_it_rejects_colors_that_are_not_six_digit_hex(): void
    {
        $this->editor()
            ->set('colorPrimary', '#fff;}body{')
            ->set('colorAccent', 'red')
            ->set('colorDark', '#12345')
            ->call('save')
            ->assertHasErrors(['colorPrimary', 'colorAccent', 'colorDark']);

        $this->assertSame('#FF671F', $this->company->fresh()->color_primary);
    }

    public function test_domains_are_added_lowercase_and_removed(): void
    {
        $this->editor()
            ->set('newDomain', ' Segurexlatam.COM ')
            ->call('addDomain')
            ->assertHasNoErrors()
            ->assertSet('domains', ['segurex.com', 'segurexlatam.com'])
            ->assertSet('newDomain', '')
            ->call('removeDomain', 0)
            ->assertSet('domains', ['segurexlatam.com']);
    }

    public function test_invalid_domains_are_rejected(): void
    {
        $this->editor()->set('newDomain', 'no es un dominio')->call('addDomain')->assertHasErrors(['newDomain']);
        $this->editor()->set('newDomain', 'https://segurex.com')->call('addDomain')->assertHasErrors(['newDomain']);
        $this->editor()->set('domains', ['Segurex.com'])->call('save')->assertHasErrors(['domains.0']);
        $this->editor()->set('domains', [])->call('save')->assertHasErrors(['domains']);
    }

    public function test_a_domain_belongs_to_only_one_company(): void
    {
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'domains' => ['derivadoscol.com']]);

        $this->editor()
            ->set('newDomain', 'derivadoscol.com')
            ->call('addDomain')
            ->assertHasErrors(['newDomain'])
            ->assertSee('Soluciones y Derivados');

        $this->editor()
            ->set('domains', ['segurex.com', 'derivadoscol.com'])
            ->call('save')
            ->assertHasErrors(['domains.1']);

        $this->assertSame(['segurex.com'], $this->company->fresh()->domains);
    }

    public function test_quick_links_must_use_https(): void
    {
        $this->editor()
            ->set('quickLinks', [['label' => 'SharePoint', 'url' => 'http://segurex.sharepoint.com']])
            ->call('save')
            ->assertHasErrors(['quickLinks.0.url']);

        $this->editor()
            ->set('quickLinks', [['label' => 'Script', 'url' => 'javascript:alert(1)']])
            ->call('save')
            ->assertHasErrors(['quickLinks.0.url']);

        $this->editor()
            ->set('quickLinks', [['label' => '', 'url' => 'https://segurex.com']])
            ->call('save')
            ->assertHasErrors(['quickLinks.0.label']);
    }

    public function test_at_most_eight_quick_links_are_allowed(): void
    {
        $links = array_map(fn ($i) => ['label' => "Enlace {$i}", 'url' => "https://segurex.com/{$i}"], range(1, 9));

        $this->editor()->set('quickLinks', $links)->call('save')->assertHasErrors(['quickLinks']);

        $this->editor()
            ->set('quickLinks', array_slice($links, 0, 8))
            ->call('addQuickLink')
            ->assertCount('quickLinks', 8);
    }

    public function test_saving_persists_the_changes_and_refreshes_the_tenant_cache(): void
    {
        Cache::forever(TenantDirectory::CACHE_KEY, ['stale']);

        $this->editor()
            ->set('name', 'Segurex Latam S.A.S.')
            ->set('shortName', 'Segurex')
            ->set('colorPrimary', '#183f94')
            ->set('colorAccent', '#3E60A8')
            ->set('colorDark', '#03256C')
            ->set('quickLinks', [['label' => 'Intranet', 'url' => 'https://intranet.segurex.com']])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.companies.show', 'segurex'));

        $company = $this->company->fresh();
        $this->assertSame('Segurex Latam S.A.S.', $company->name);
        $this->assertSame('#183F94', $company->color_primary);
        $this->assertSame([['label' => 'Intranet', 'url' => 'https://intranet.segurex.com']], $company->quick_links);
        $this->assertFalse(Cache::has(TenantDirectory::CACHE_KEY));
    }

    public function test_deactivation_asks_for_confirmation(): void
    {
        $this->editor()
            ->set('isActive', false)
            ->call('save')
            ->assertSet('confirmingDeactivation', true)
            ->assertSee('Desactivar empresa');

        $this->assertTrue($this->company->fresh()->is_active);

        $this->editor()
            ->set('isActive', false)
            ->call('save')
            ->call('confirmDeactivation')
            ->assertHasNoErrors();

        $this->assertFalse($this->company->fresh()->is_active);
    }

    public function test_a_super_admin_cannot_deactivate_their_own_company(): void
    {
        $this->admin->update(['company_id' => $this->company->id]);

        $this->editor($this->admin->fresh())
            ->set('isActive', false)
            ->call('save')
            ->assertHasErrors(['isActive'])
            ->assertSee('No puedes desactivar la empresa con la que iniciaste sesión.')
            ->call('confirmDeactivation')
            ->assertHasErrors(['isActive']);

        $this->assertTrue($this->company->fresh()->is_active);
    }

    public function test_the_preview_uses_the_colors_being_edited(): void
    {
        $this->editor()
            ->set('colorPrimary', '#183F94')
            ->assertSee('--brand-button:#183F94', false)
            ->call('setPreview', 'dark')
            ->assertSet('preview', 'dark')
            ->call('setPreview', 'nonsense')
            ->assertSet('preview', 'dark');

        $this->assertSame('#FF671F', $this->company->fresh()->color_primary);
    }
}
