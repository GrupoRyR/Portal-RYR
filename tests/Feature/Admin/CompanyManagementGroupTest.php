<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Grupo de Gerencia» in Administración › Empresas: the object ID of the
 * security group of the company's tenant whose members get the
 * `management` role (App\Auth\ManagementGroup). Super admins only, audited.
 */
class CompanyManagementGroupTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

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
        ]);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('super_admin');
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->admin)->test(CompanyEditor::class, ['company' => $this->company->fresh()]);
    }

    public function test_the_page_shows_the_field_with_its_help(): void
    {
        $this->company->update(['management_group_id' => self::GROUP]);

        $this->actingAs($this->admin)->get(route('admin.companies.show', $this->company))
            ->assertOk()
            ->assertSeeText('Grupo de Gerencia')
            ->assertSeeText('APP-PortalGerencia')
            ->assertSee('value="'.self::GROUP.'"', false);
    }

    public function test_the_group_id_is_saved_lowercase_and_audited(): void
    {
        $this->editor()
            ->assertSet('managementGroupId', '')
            ->set('managementGroupId', '  '.strtoupper(self::GROUP).' ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::GROUP, $this->company->fresh()->management_group_id);

        $entry = Activity::query()->where('log_name', 'companies')->where('event', 'updated')->latest('id')->first();
        $this->assertSame(['management_group_id' => self::GROUP], $entry->properties['attributes']);
        $this->assertSame(['management_group_id' => null], $entry->properties['old']);
        $this->assertTrue($entry->causer->is($this->admin));

        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertSeeTextInOrder(['Jhon Castro', 'Cambió la empresa Segurex Latam', 'Grupo de Gerencia', self::GROUP]);
    }

    public function test_clearing_the_field_stores_no_group(): void
    {
        $this->company->update(['management_group_id' => self::GROUP]);

        $this->editor()
            ->assertSet('managementGroupId', self::GROUP)
            ->set('managementGroupId', ' ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($this->company->fresh()->management_group_id);
    }

    public function test_only_an_object_id_is_accepted(): void
    {
        foreach (['APP-PortalGerencia', 'bbbbbbbb-0000-4000-8000', 'bbbbbbbb-0000-4000-8000-00000000000g', self::GROUP.'x'] as $value) {
            $this->editor()
                ->set('managementGroupId', $value)
                ->call('save')
                ->assertHasErrors('managementGroupId')
                ->assertSee('Pega el Object ID del grupo (GUID) tal como aparece en Microsoft Entra, no su nombre.');
        }

        $this->assertNull($this->company->fresh()->management_group_id);
    }

    public function test_portal_admins_cannot_edit_companies(): void
    {
        $portalAdmin = User::factory()->create();
        $portalAdmin->assignRole('portal_admin');

        Livewire::actingAs($portalAdmin)
            ->test(CompanyEditor::class, ['company' => $this->company])
            ->assertForbidden();
    }
}
