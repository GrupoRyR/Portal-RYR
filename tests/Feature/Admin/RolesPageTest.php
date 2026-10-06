<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
    }

    private function userWith(string $role, array $attributes = []): User
    {
        $user = User::factory()->microsoft($this->segurex)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    // --- Access -------------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    }

    public function test_super_and_portal_admins_can_open_the_page(): void
    {
        foreach (['super_admin', 'portal_admin'] as $role) {
            $this->actingAs($this->userWith($role))->get(route('admin.roles.index'))
                ->assertOk()
                ->assertSeeText('Roles')
                ->assertSeeText('Qué puede hacer cada rol');
        }
    }

    public function test_announcement_editors_and_plain_users_are_forbidden(): void
    {
        $this->actingAs($this->userWith('announcements_editor'))->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.roles.index'))->assertForbidden();
    }

    public function test_the_sidebar_links_to_roles_for_admins_only(): void
    {
        $link = 'href="'.route('admin.roles.index').'"';

        $this->actingAs($this->userWith('portal_admin'))->get(route('admin.roles.index'))
            ->assertSee($link.' aria-current="page"', false);

        $this->actingAs($this->userWith('announcements_editor'))->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertDontSee($link, false)
            ->assertDontSeeText('Roles');
    }

    // --- Matrix and members ----------------------------------------------

    public function test_the_matrix_is_rendered_from_the_role_matrix(): void
    {
        $this->actingAs($this->userWith('super_admin'))->get(route('admin.roles.index'))
            ->assertSeeInOrder(['Súper administrador', 'Administrador', 'Editor de anuncios'])
            ->assertSeeInOrder(['Empresas', 'Integraciones', 'Aplicaciones y accesos', 'Categorías', 'Anuncios', 'Roles', 'Auditoría e informe de uso', 'Configuración'])
            ->assertSee('aria-label="Sí"', false)
            ->assertSee('aria-label="No"', false)
            ->assertSeeText('Solo editores');
    }

    public function test_members_of_each_role_are_listed(): void
    {
        $this->userWith('super_admin', ['name' => 'Jhon Castro', 'email' => 'jhon@segurex.com']);
        $this->userWith('portal_admin', ['name' => 'Leonardo Herrera']);
        $this->userWith('announcements_editor', ['name' => 'Paula Gómez']);

        $this->actingAs($this->userWith('portal_admin', ['name' => 'Otra Admin']))->get(route('admin.roles.index'))
            ->assertSeeTextInOrder(['Súper administrador', 'Jhon Castro', 'jhon@segurex.com'])
            ->assertSeeText('correos configurados')
            ->assertSeeTextInOrder(['Administradores', 'Leonardo Herrera', 'Segurex'])
            ->assertSeeText('Se asigna solo: entra quien esté en el grupo APP-PortalAdmin de su empresa')
            ->assertSeeTextInOrder(['Editores de anuncios', 'Paula Gómez']);
    }

    public function test_an_empty_editors_list_says_so(): void
    {
        $this->actingAs($this->userWith('super_admin'))->get(route('admin.roles.index'))
            ->assertSeeText('Nadie todavía.')
            ->assertSeeText('Agregar persona');
    }

    // --- Emergency account card ------------------------------------------

    public function test_the_card_says_when_there_is_no_emergency_account(): void
    {
        $this->actingAs($this->userWith('super_admin'))->get(route('admin.roles.index'))
            ->assertSeeText('Cuenta de emergencia')
            ->assertSeeText('No hay cuenta de emergencia.')
            ->assertSeeText('php artisan portal:emergency-account');
    }

    public function test_the_card_shows_a_never_used_account_with_2fa(): void
    {
        $account = User::factory()->emergency('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')->create(['email' => 'emergencia@segurex.com']);

        $response = $this->actingAs($this->userWith('super_admin'))->get(route('admin.roles.index'))
            ->assertSeeText('2FA activo')
            ->assertSeeText('nunca usada')
            ->assertSeeText('emergencia@segurex.com');

        // Never a secret.
        $response->assertDontSee('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
        $response->assertDontSee((string) $account->password);
    }

    public function test_the_card_shows_unconfirmed_2fa_and_the_last_use(): void
    {
        User::factory()->emergency()->create([
            'two_factor_confirmed_at' => null,
            'last_login_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->userWith('portal_admin'))->get(route('admin.roles.index'))
            ->assertSeeText('2FA sin confirmar')
            ->assertSeeText('usada por última vez el 3 de octubre de 2026')
            ->assertDontSeeText('nunca usada');
    }
}
