<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(?string $role): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_the_list_shows_every_company_and_the_first_editor(): void
    {
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'slug' => 'derivados', 'domains' => ['derivadoscol.com']]);
        Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex', 'domains' => ['segurex.com'], 'is_active' => false]);

        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSeeInOrder(['Segurex Latam', 'Soluciones y Derivados'])
            ->assertSee('Inactiva')
            ->assertSee('href="'.route('admin.companies.show', 'segurex').'" aria-current="true"', false)
            ->assertSee('Datos de la empresa')
            ->assertSee('value="Segurex Latam"', false);
    }

    public function test_a_company_page_opens_its_editor(): void
    {
        Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'slug' => 'derivados']);

        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.show', 'derivados'))
            ->assertOk()
            ->assertSee('href="'.route('admin.companies.show', 'derivados').'" aria-current="true"', false)
            ->assertSee('value="Soluciones y Derivados"', false)
            ->assertSee('Marca')
            ->assertSee('Vista previa');
    }

    public function test_the_list_has_an_empty_state(): void
    {
        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('Todavía no hay empresas registradas.');
    }

    public function test_unknown_companies_are_not_found(): void
    {
        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.show', 'nadie'))->assertNotFound();
    }

    public function test_portal_admins_and_users_without_role_are_forbidden(): void
    {
        $company = Company::factory()->create(['slug' => 'segurex']);

        foreach (['portal_admin', 'announcements_editor', null] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('admin.companies.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.companies.show', $company))->assertForbidden();
        }
    }

    public function test_tenant_and_slug_are_read_only(): void
    {
        $company = Company::factory()->create([
            'slug' => 'segurex',
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
            'consent_verified_at' => now(),
        ]);

        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.show', $company))
            ->assertSee('97cd024c-cd7a-419b-b385-f47b5f5816c8')
            ->assertSee('Consentimiento verificado')
            ->assertSee('Cambiar')
            ->assertDontSee('wire:model="tenant', false)
            ->assertDontSee('wire:model="slug', false);
    }

    public function test_pending_consent_is_shown(): void
    {
        $company = Company::factory()->create(['consent_verified_at' => null]);

        $this->actingAs($this->user('super_admin'))->get(route('admin.companies.show', $company))
            ->assertSee('Consentimiento pendiente');
    }
}
