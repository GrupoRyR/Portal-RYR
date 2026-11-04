<?php

namespace Tests\Feature\Admin;

use App\Admin\RoleMatrix;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page of Administración › Mercados (App\Livewire\Admin\MarketManager)
 * and its sidebar link: admins and announcements editors with a company
 * open it; nobody else gets the page nor the link.
 */
class MarketsPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->company = Company::factory()->create(['short_name' => 'Segurex']);
    }

    public function test_admins_and_editors_with_a_company_open_the_page(): void
    {
        foreach (['super_admin', 'portal_admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('admin.markets.index'))
                ->assertOk()
                ->assertSee('<h1 class="text-[2rem] leading-tight font-bold">Mercados</h1>', false)
                ->assertSee('Insumos que ve esta empresa')
                ->assertSee('Precios ingresados a mano en Segurex');
        }

        $this->actingAs($this->user('announcements_editor', $this->company))->get(route('admin.markets.index'))
            ->assertOk()
            ->assertSee('Precios ingresados a mano en Segurex');
    }

    public function test_everyone_else_gets_a_403_and_guests_go_to_sign_in(): void
    {
        $this->actingAs($this->user('announcements_editor'))->get(route('admin.markets.index'))->assertForbidden();
        $this->actingAs($this->user('management', $this->company))->get(route('admin.markets.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]))->get(route('admin.markets.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.markets.index'))->assertRedirect(route('login'));
    }

    public function test_the_address_is_admin_mercados(): void
    {
        $this->assertSame(url('/admin/mercados'), route('admin.markets.index'));
    }

    public function test_the_sidebar_links_to_the_page_only_for_those_who_may_open_it(): void
    {
        $link = 'href="'.route('admin.markets.index').'"';

        $this->actingAs($this->user('portal_admin'))->get(route('admin.markets.index'))
            ->assertSee($link.' aria-current="page"', false);

        // Admins: in «Catálogo», after Noticias (super admins) or Eventos.
        $this->actingAs($this->user('super_admin'))->get(route('admin.apps.index'))
            ->assertOk()
            ->assertSeeInOrder(['Catálogo', 'Eventos', 'Noticias', 'Mercados', 'Organización'])
            ->assertSee($link, false);

        $this->actingAs($this->user('announcements_editor', $this->company))->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSee($link, false);

        $this->actingAs($this->user('announcements_editor'))->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertDontSee($link, false);
    }

    public function test_the_roles_page_shows_the_mercados_row(): void
    {
        $section = collect(RoleMatrix::sections())->firstWhere('label', 'Mercados');

        $this->assertNotNull($section);
        $this->assertSame('Solo de su empresa', $section->note('announcements_editor'));
        $this->assertNull($section->note('portal_admin'));

        $this->actingAs($this->user('portal_admin'))->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSeeInOrder(['Estado de TI', 'Mercados', 'Roles']);
    }

    private function user(string $role, ?Company $company = null): User
    {
        return tap(User::factory()->create(['company_id' => $company?->id]))->assignRole($role);
    }
}
