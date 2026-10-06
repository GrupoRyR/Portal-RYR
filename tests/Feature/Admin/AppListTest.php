<?php

namespace Tests\Feature\Admin;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AppListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $derivados;

    private AppCategory $ventas;

    private AppCategory $tesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'slug' => 'derivados']);
        $this->ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas']);
        $this->tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('portal_admin');
    }

    // --- Access -----------------------------------------------------------

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(route('admin.apps.index'))->assertRedirect(route('login'));
    }

    public function test_users_without_an_admin_role_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.apps.index'))
            ->assertForbidden()
            ->assertSee('No tienes permiso para ver esta página.');
    }

    public function test_portal_admins_and_super_admins_see_the_list(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_admin');

        $this->actingAs($this->admin)->get(route('admin.apps.index'))->assertOk()->assertSee('Aplicaciones');
        $this->actingAs($super)->get(route('admin.apps.index'))->assertOk();
    }

    public function test_the_admin_home_sends_each_role_to_its_first_section(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_admin');

        $this->actingAs($this->admin)->get('/admin')->assertRedirect(route('admin.apps.index'));
        $this->actingAs($super)->get('/admin')->assertRedirect(route('admin.companies.index'));
    }

    public function test_the_sidebar_links_to_the_apps_section(): void
    {
        $this->actingAs($this->admin)->get(route('admin.apps.index'))
            ->assertSee('href="'.route('admin.apps.index').'" aria-current="page"', false);
    }

    public function test_the_policy_never_allows_deleting_apps(): void
    {
        $super = User::factory()->create();
        $super->assignRole('super_admin');
        $app = App::factory()->create();

        $this->assertTrue($super->can('viewAny', App::class));
        $this->assertTrue($this->admin->can('create', App::class));
        $this->assertTrue($this->admin->can('update', $app));
        $this->assertFalse($super->can('delete', $app));
        $this->assertFalse($this->admin->can('delete', $app));
        $this->assertFalse(User::factory()->create()->can('viewAny', App::class));
    }

    // --- Tiles ------------------------------------------------------------

    public function test_the_tiles_summarize_the_whole_catalog(): void
    {
        $this->app(['name' => 'Toma Pedidos', 'login_type' => 'microsoft']);
        $this->app(['name' => 'Portafolio']);
        $this->app(['name' => 'Cartera', 'external_status' => 'down', 'external_status_at' => now()->subMinutes(25)]);
        $this->app(['name' => 'Nómina'], 'maintenance');
        $this->app(['name' => 'Cámaras'], 'local');
        $this->app(['name' => 'Vieja', 'login_type' => 'microsoft'], 'hidden');

        $this->list()
            ->assertSeeInOrder(['data-tile="published"', '5'], false)
            ->assertSeeInOrder(['data-tile="failing"', '1'], false)
            ->assertSeeInOrder(['data-tile="maintenance"', '1'], false)
            // Published web apps only: four, one of them with Microsoft.
            ->assertSeeInOrder(['data-tile="microsoft"', '1 de 4'], false);
    }

    // --- Rows -------------------------------------------------------------

    public function test_each_row_describes_the_app(): void
    {
        $app = $this->app([
            'name' => 'Visualizador de cartera',
            'slug' => 'cartera',
            'hosting' => 'vercel',
            'login_type' => 'own',
            'app_category_id' => $this->tesoreria->id,
            'external_status' => 'down',
            'external_status_at' => now()->subMinutes(25),
        ]);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->list()
            ->assertSeeInOrder(['Visualizador de cartera', 'cartera', 'Segurex', 'Tesorería', 'Web · Vercel', 'Propio', 'Con fallas', 'reportado hace 25 minutos', 'APP-Cartera']);
    }

    public function test_local_apps_and_all_company_apps_are_labelled(): void
    {
        $this->app(['name' => 'Cámaras', 'available_to_all_companies' => true], 'local');

        $this->list()->assertSeeInOrder(['Cámaras', 'Todas', 'Instalada en el equipo', 'No aplica']);
    }

    public function test_who_sees_it_summarizes_the_rules(): void
    {
        $app = $this->app(['name' => 'Toma Pedidos']);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-TomaPedidos');
        AppAccessRule::forCompany($app, $this->segurex);
        AppAccessRule::forUser($app, User::factory()->microsoft($this->segurex)->create());
        AppAccessRule::forUser($app, User::factory()->microsoft($this->segurex)->create());
        $this->app(['name' => 'Sin reglas']);

        $this->list()
            ->assertSeeInOrder(['Toma Pedidos', 'APP-TomaPedidos', 'Toda la empresa', '2 usuarios'])
            ->assertSeeInOrder(['Sin reglas', 'Nadie todavía']);
    }

    // --- Filters ----------------------------------------------------------

    public function test_the_search_matches_name_or_slug(): void
    {
        $this->app(['name' => 'Visualizador de cartera', 'slug' => 'cartera']);
        $this->app(['name' => 'Toma Pedidos', 'slug' => 'toma-pedidos']);

        $this->list(['q' => 'visualizador'])->assertSee('Visualizador de cartera')->assertDontSee('Toma Pedidos');
        $this->list(['q' => 'toma-ped'])->assertSee('Toma Pedidos')->assertDontSee('Visualizador de cartera');
    }

    public function test_the_company_filter_includes_apps_for_all_companies(): void
    {
        $this->app(['name' => 'Solo Derivados'], companies: [$this->derivados]);
        $this->app(['name' => 'Solo Segurex']);
        $this->app(['name' => 'Para todas', 'available_to_all_companies' => true], companies: []);

        $this->list(['empresa' => 'derivados'])
            ->assertSee('Solo Derivados')
            ->assertSee('Para todas')
            ->assertDontSee('Solo Segurex');
    }

    public function test_the_status_filter(): void
    {
        $this->app(['name' => 'App sana']);
        $this->app(['name' => 'App caida', 'external_status' => 'degraded', 'external_status_at' => now()]);
        $this->app(['name' => 'App en arreglo'], 'maintenance');
        $this->app(['name' => 'App escondida'], 'hidden');

        $this->list(['estado' => 'activa'])->assertSee('App sana')->assertDontSee('App caida')->assertDontSee('App en arreglo')->assertDontSee('App escondida');
        $this->list(['estado' => 'fallas'])->assertSee('App caida')->assertDontSee('App sana');
        $this->list(['estado' => 'mantenimiento'])->assertSee('App en arreglo')->assertDontSee('App sana');
        $this->list(['estado' => 'oculta'])->assertSee('App escondida')->assertDontSee('App sana');
    }

    public function test_the_category_filter_and_the_selected_values_stay_in_the_form(): void
    {
        $this->app(['name' => 'De ventas', 'app_category_id' => $this->ventas->id]);
        $this->app(['name' => 'De tesorería', 'app_category_id' => $this->tesoreria->id]);

        $this->list(['categoria' => 'ventas', 'q' => 'de'])
            ->assertSee('De ventas')
            ->assertDontSee('De tesorería')
            ->assertSee('<option value="ventas" selected>', false)
            ->assertSee('value="de"', false);
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        $this->app(['name' => 'App sana']);

        $this->list(['estado' => 'rara', 'empresa' => 'nadie', 'categoria' => ['x']])->assertSee('App sana');
    }

    public function test_an_empty_result_says_so(): void
    {
        $this->list(['q' => 'nada'])->assertSee('Ninguna aplicación coincide con los filtros.');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.apps.index', $query))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Company>|null  $companies  defaults to Segurex
     */
    private function app(array $attributes = [], ?string $state = null, ?array $companies = null): App
    {
        $factory = App::factory();

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        return $factory
            ->forCompanies(...($companies ?? [$this->segurex]))
            ->create(['app_category_id' => $this->ventas->id, ...$attributes]);
    }
}
