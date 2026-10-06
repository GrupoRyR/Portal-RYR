<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_MESSAGE = 'No tienes permiso para ver esta página.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get(route('admin.companies.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_an_admin_role_are_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')
            ->assertForbidden()
            ->assertSee(self::FORBIDDEN_MESSAGE)
            ->assertSee('lang="es"', false);
    }

    public function test_announcement_editors_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('announcements_editor');

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_portal_admins_can_open_the_admin_area(): void
    {
        $user = User::factory()->create();
        $user->assignRole('portal_admin');

        // Portal admins cannot manage companies: they land on the apps.
        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.apps.index'));
    }

    public function test_portal_admins_cannot_manage_companies(): void
    {
        $user = User::factory()->create();
        $user->assignRole('portal_admin');

        $this->actingAs($user)->get(route('admin.companies.index'))
            ->assertForbidden()
            ->assertSee(self::FORBIDDEN_MESSAGE);
    }

    public function test_portal_admins_do_not_see_the_companies_link(): void
    {
        $user = User::factory()->create();
        $user->assignRole('portal_admin');

        $this->actingAs($user)->get(route('admin.apps.index'))
            ->assertOk()
            ->assertSee('Aplicaciones')
            ->assertDontSee('href="'.route('admin.companies.index').'"', false);
    }

    public function test_super_admins_see_the_companies_page_inside_the_admin_shell(): void
    {
        $user = User::factory()->create(['name' => 'Jhon Castro']);
        $user->assignRole('super_admin');

        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.companies.index'));

        $this->actingAs($user)->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('aria-label="Administración"', false)
            ->assertSeeInOrder(['Catálogo', 'Aplicaciones', 'Categorías', 'Anuncios'])
            ->assertSeeInOrder(['Organización', 'Empresas', 'Roles', 'Integraciones'])
            ->assertSeeInOrder(['Seguimiento', 'Auditoría', 'Informe de uso', 'Configuración'])
            ->assertSee('href="'.route('admin.companies.index').'" aria-current="page"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('Volver al portal')
            ->assertSee('Jhon Castro')
            ->assertSee('Súper administrador');
    }

    public function test_admin_pages_load_livewire(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->actingAs($user)->get(route('admin.companies.index'))
            ->assertSee('window.livewireScriptConfig', false)
            ->assertSee('/build/assets/livewire-', false);
    }

    public function test_sections_that_do_not_exist_yet_are_not_links(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $response = $this->actingAs($user)->get(route('admin.companies.index'));

        $response->assertSee('Próximamente');
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('admin.companies.index').'"'));
        $this->assertStringNotContainsString('href="#"', $response->getContent());
    }

    public function test_the_shell_offers_a_skip_link_and_an_accessible_menu_button(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->actingAs($user)->get(route('admin.companies.index'))
            ->assertSee('href="#contenido"', false)
            ->assertSee('id="contenido"', false)
            ->assertSee('aria-controls="admin-sidebar"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('id="admin-sidebar"', false);
    }

    public function test_the_shell_uses_the_company_brand_and_name(): void
    {
        $company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
        ]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('super_admin');

        $this->actingAs($user)->get(route('admin.companies.index'))
            ->assertOk()
            ->assertSee('Segurex Latam')
            ->assertSee(':root{--brand-primary:#FF671F;', false);
    }

    public function test_portal_admins_get_their_role_label(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['portal_admin', 'super_admin']);

        // The highest role wins.
        $this->actingAs($user)->get(route('admin.companies.index'))->assertSee('Súper administrador');
    }
}
