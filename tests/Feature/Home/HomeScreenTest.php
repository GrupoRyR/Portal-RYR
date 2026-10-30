<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use App\View\Home\QuickLinks;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HomeScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_greeting_names_the_user_in_bogota_time(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 5)->setTime(23, 30)); // 18:30 in Bogotá
        $this->openableApp();

        $this->home()
            ->assertSeeText('Buenas tardes, Ana')
            ->assertSeeText('Lunes, 5 de octubre')
            ->assertSeeText('Tienes 1 aplicación disponible en Segurex Latam.');
    }

    public function test_only_visible_apps_are_rendered(): void
    {
        $this->openableApp(['name' => 'Toma Pedidos SAP']);
        $this->openableApp(['name' => 'App oculta', 'status' => App::STATUS_HIDDEN]);
        App::factory()->forCompanies($this->company)->create(['name' => 'App sin regla']);

        $groupApp = App::factory()->forCompanies($this->company)->create(['name' => 'Visualizador de cartera']);
        AppAccessRule::forGroup($groupApp, $this->company, 'APP-Cartera');
        $otherGroup = App::factory()->forCompanies($this->company)->create(['name' => 'App de gerencia']);
        AppAccessRule::forGroup($otherGroup, $this->company, 'APP-Gerencia');
        $this->user->syncGroups([['id' => 'g-1', 'display_name' => 'APP-Cartera', 'security_group' => true]]);

        $other = Company::factory()->create();
        $foreign = App::factory()->forCompanies($other)->create(['name' => 'App de otra empresa']);
        AppAccessRule::forCompany($foreign, $other);

        $this->home()
            ->assertSeeText('Toma Pedidos SAP')
            ->assertSeeText('Visualizador de cartera')
            ->assertDontSeeText('App oculta')
            ->assertDontSeeText('App sin regla')
            ->assertDontSeeText('App de gerencia')
            ->assertDontSeeText('App de otra empresa');
    }

    public function test_favorites_come_first_and_appear_once(): void
    {
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 10]);
        $this->openableApp(['name' => 'Portafolio', 'slug' => 'portafolio', 'app_category_id' => $ventas->id]);
        $favorite = $this->openableApp(['name' => 'Toma Pedidos SAP', 'slug' => 'toma-pedidos', 'app_category_id' => $ventas->id]);
        $this->user->favoriteApps()->attach($favorite);

        $html = $this->home()->getContent();

        $this->assertSame(1, substr_count($html, 'id="app-toma-pedidos"'));
        $this->assertLessThan(strpos($html, 'id="app-portafolio"'), strpos($html, 'id="app-toma-pedidos"'));
        $this->assertLessThan(strpos($html, 'id="app-toma-pedidos"'), strpos($html, 'id="favoritos"'));
        $this->assertStringContainsString('aria-pressed="true" aria-label="Marcar Toma Pedidos SAP como favorita"', $html);
        $this->assertStringContainsString('aria-pressed="false" aria-label="Marcar Portafolio como favorita"', $html);
    }

    public function test_categories_follow_their_order_and_empty_ones_are_hidden(): void
    {
        $tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria', 'sort_order' => 30]);
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 10]);
        AppCategory::factory()->create(['name' => 'Compras', 'slug' => 'compras', 'sort_order' => 20]);
        $gerencia = AppCategory::factory()->create(['name' => 'Gerencia', 'slug' => 'gerencia', 'sort_order' => 40]);
        $this->openableApp(['app_category_id' => $tesoreria->id]);
        $this->openableApp(['app_category_id' => $ventas->id]);
        $onlyFavorite = $this->openableApp(['app_category_id' => $gerencia->id]);
        $this->user->favoriteApps()->attach($onlyFavorite);

        $response = $this->home()->assertDontSeeText('Compras');
        $html = $response->getContent();

        $this->assertLessThan(strpos($html, 'data-category="tesoreria"'), strpos($html, 'data-category="ventas"'));
        // Emptied by favorites: kept for the favorite toggle, but hidden.
        $this->assertMatchesRegularExpression('/<section[^>]*data-category="gerencia"[^>]*hidden/', $html);
        $this->assertDoesNotMatchRegularExpression('/<section[^>]*data-category="ventas"[^>]*hidden/', $html);
    }

    public function test_each_status_chip_is_shown(): void
    {
        $this->openableApp(['login_type' => 'microsoft']);
        $this->openableApp(['status' => App::STATUS_MAINTENANCE]);
        $failing = $this->openableApp(['login_type' => 'microsoft']);
        $failing->forceFill(['external_status_at' => now(), 'external_status' => 'down', 'external_status_message' => 'SAP no responde'])->save();
        $degraded = $this->openableApp(['login_type' => 'microsoft']);
        $degraded->forceFill(['external_status_at' => now(), 'external_status' => 'degraded'])->save();
        $this->openableApp(['login_type' => 'own']);
        $local = App::factory()->local()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($local, $this->company);

        $this->home()
            ->assertSeeText('Activa')
            ->assertSeeText('En mantenimiento')
            ->assertSeeText('Con fallas')
            ->assertSeeText('SAP no responde')
            ->assertSeeText('Estamos revisando')
            ->assertSeeText('Inicio de sesión propio')
            ->assertSeeText('Instalada en tu equipo');
    }

    public function test_the_failure_chip_is_not_shown_for_healthy_apps(): void
    {
        $app = $this->openableApp(['login_type' => 'microsoft']);
        $app->forceFill(['external_status_at' => now(), 'external_status' => 'operational'])->save();

        $this->home()->assertSeeText('Activa')->assertDontSeeText('Con fallas');
    }

    public function test_only_web_apps_outside_maintenance_open_in_a_new_tab(): void
    {
        $newTab = $this->openableApp(['open_in' => App::OPEN_NEW]);
        $sameTab = $this->openableApp(['open_in' => App::OPEN_SAME]);
        $maintenance = $this->openableApp(['open_in' => App::OPEN_NEW, 'status' => App::STATUS_MAINTENANCE]);
        $local = App::factory()->local()->forCompanies($this->company)->create(['open_in' => App::OPEN_NEW]);
        AppAccessRule::forCompany($local, $this->company);

        $html = $this->home()->getContent();

        $this->assertStringContainsString('href="'.route('apps.open', $newTab).'" target="_blank" rel="noopener"', $html);

        foreach ([$sameTab, $maintenance, $local] as $app) {
            $this->assertStringContainsString('href="'.route('apps.open', $app).'"', $html);
            $this->assertStringNotContainsString('href="'.route('apps.open', $app).'" target="_blank"', $html);
        }
    }

    public function test_icons_swap_for_the_dark_scheme(): void
    {
        $this->openableApp([
            'icon_path' => 'icons/apps/cartera.svg',
            'icon_dark_path' => 'icons/apps/cartera-dark.svg',
        ]);

        $this->home()
            ->assertSee('<source srcset="'.asset('icons/apps/cartera-dark.svg').'" media="(prefers-color-scheme: dark)">', false)
            ->assertSee('src="'.asset('icons/apps/cartera.svg').'" alt="" width="48" height="48"', false);
    }

    public function test_recent_apps_are_shown_when_there_are_launches(): void
    {
        $app = $this->openableApp(['name' => 'Cuentas por pagar']);

        $this->home()->assertDontSee('id="recientes"', false);

        AppLaunch::create([
            'user_id' => $this->user->id,
            'app_id' => $app->id,
            'company_id' => $this->company->id,
            'device' => 'desktop',
            'launched_at' => now()->subMinutes(5),
        ]);

        $this->home()->assertSee('id="recientes"', false)->assertSeeText('Recientes');
    }

    public function test_quick_links_list_the_defaults_and_the_company_links(): void
    {
        $this->openableApp();
        $this->company->update(['quick_links' => [
            ['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com'],
        ]]);

        $response = $this->home()->assertSeeText('Microsoft 365');

        foreach (QuickLinks::DEFAULTS as $label => $url) {
            $response->assertSee('href="'.$url.'"', false)->assertSeeText($label);
        }

        $response->assertSee('href="https://segurex.sharepoint.com"', false)->assertSeeText('SharePoint');
    }

    public function test_a_user_without_apps_sees_the_empty_state_and_the_quick_links(): void
    {
        $this->home()
            ->assertSeeText('Aún no tienes aplicaciones asignadas')
            ->assertSeeText('Solicítalas a TI.')
            ->assertSee('href="'.QuickLinks::DEFAULTS['Outlook'].'"', false)
            ->assertDontSee('id="favoritos"', false);
    }

    public function test_the_search_filters_on_the_server_without_accents(): void
    {
        $this->openableApp(['name' => 'Cámaras', 'description' => 'Monitoreo de seguridad']);
        $this->openableApp(['name' => 'Toma Pedidos SAP', 'description' => 'Pedidos de venta']);

        $this->home('?q=camaras')
            ->assertSeeText('Cámaras')
            ->assertDontSeeText('Toma Pedidos SAP')
            ->assertSeeText('1 resultado')
            ->assertSee('value="camaras"', false);

        $this->home('?q=n%C3%B3mina')
            ->assertSeeText('No encontramos aplicaciones para «nómina».')
            ->assertDontSeeText('Cámaras');
    }

    public function test_the_admin_link_is_only_for_admins(): void
    {
        $this->seed(RoleSeeder::class);

        $this->home()->assertDontSee('href="'.route('admin.index').'"', false);

        foreach (['super_admin', 'portal_admin'] as $role) {
            $user = User::factory()->microsoft($this->company)->create();
            $user->assignRole($role);

            $this->actingAs($user->fresh())->get('/')
                ->assertOk()
                ->assertSee('href="'.route('admin.index').'"', false)
                ->assertSeeText('Administración');
        }
    }

    public function test_the_account_menu_signs_out_with_a_post_form(): void
    {
        $this->home()
            ->assertSee('<form method="POST" action="'.route('logout').'"', false)
            ->assertSeeText('Cerrar sesión')
            ->assertDontSeeText('Soporte TI');
    }

    public function test_the_page_ships_its_own_script_and_no_livewire_or_alpine(): void
    {
        $this->openableApp();

        $this->home()
            ->assertSee('/build/assets/home-', false)
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false)
            ->assertDontSee('alpine', false);
    }

    public function test_the_favorite_star_works_without_javascript(): void
    {
        $app = $this->openableApp(['slug' => 'cartera', 'name' => 'Cartera']);
        $this->user->favoriteApps()->attach($app);

        $this->home('?q=a')
            ->assertSee('action="'.route('favorites.destroy', $app).'"', false)
            ->assertSee('<input type="hidden" name="_method" value="DELETE">', false)
            ->assertSee('<input type="hidden" name="q" value="a">', false);
    }

    public function test_the_query_count_does_not_grow_with_the_catalog(): void
    {
        $this->openableApp();
        $small = $this->countQueries();

        foreach (range(1, 12) as $i) {
            $app = $this->openableApp();
            if ($i % 3 === 0) {
                $this->user->favoriteApps()->attach($app);
            }
            AppLaunch::create([
                'user_id' => $this->user->id,
                'app_id' => $app->id,
                'company_id' => $this->company->id,
                'device' => 'phone',
                'launched_at' => now()->subMinutes($i),
            ]);
        }

        $large = $this->countQueries();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }

    /**
     * The directory (odd/tasks/personas.md WU1) is one query, whatever its size.
     */
    public function test_the_query_count_does_not_grow_with_the_directory(): void
    {
        $this->openableApp();
        DirectoryEntry::factory()->forCompany($this->company)->create();
        $small = $this->countQueries();

        DirectoryEntry::factory()->forCompany($this->company)->count(60)->create();
        DirectoryEntry::factory()->forCompany(Company::factory()->create())->count(10)->create();

        $large = $this->countQueries();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }

    private function home(string $suffix = ''): TestResponse
    {
        return $this->actingAs($this->user->fresh())->get('/'.$suffix)->assertOk();
    }

    private function countQueries(): int
    {
        $user = $this->user->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        $result = $count;
        DB::flushQueryLog();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
