<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use App\View\Home\AppCard;
use App\View\Home\AppCardStatus;
use App\View\Home\CategoryGroup;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageBuilderTest extends TestCase
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

    public function test_the_greeting_names_the_user_and_counts_the_apps(): void
    {
        $this->openableApp();
        $this->openableApp();

        $page = $this->build(now: CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'));

        $this->assertSame('Buenos días', $page->salutation);
        $this->assertSame('Ana', $page->firstName);
        $this->assertSame('Lunes, 5 de octubre', $page->dateLine);
        $this->assertSame(2, $page->appCount);
        $this->assertSame('Tienes 2 aplicaciones disponibles en Segurex Latam.', $page->availability());
    }

    public function test_one_app_is_counted_in_the_singular(): void
    {
        $this->openableApp();

        $this->assertSame('Tienes 1 aplicación disponible en Segurex Latam.', $this->build()->availability());
    }

    public function test_only_visible_apps_are_listed(): void
    {
        $visible = $this->openableApp(['name' => 'Visible']);
        $this->openableApp(['name' => 'Oculta', 'status' => App::STATUS_HIDDEN]);
        App::factory()->forCompanies($this->company)->create(['name' => 'Sin regla']);
        $other = Company::factory()->create();
        $foreign = App::factory()->forCompanies($other)->create(['name' => 'Otra empresa']);
        AppAccessRule::forCompany($foreign, $other);

        $this->assertSame([$visible->id], $this->allCardIds($this->build()));
    }

    public function test_favorites_come_first_and_leave_their_category(): void
    {
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'sort_order' => 10]);
        $a = $this->openableApp(['app_category_id' => $ventas->id, 'sort_order' => 1]);
        $b = $this->openableApp(['app_category_id' => $ventas->id, 'sort_order' => 2]);
        $this->user->favoriteApps()->attach($b);

        $page = $this->build();

        $this->assertSame([$b->id], $this->ids($page->favorites));
        $this->assertTrue($page->favorites[0]->favorite);
        $this->assertSame([$a->id], $this->ids($page->categories[0]->cards));
        $this->assertSame([$a->id, $b->id], $this->allCardIds($page), 'each app once');
    }

    public function test_favorites_keep_the_order_they_were_added_in(): void
    {
        $a = $this->openableApp(['sort_order' => 1]);
        $b = $this->openableApp(['sort_order' => 2]);
        $this->user->favoriteApps()->attach($b, ['created_at' => now()->subMinute()]);
        $this->user->favoriteApps()->attach($a, ['created_at' => now()]);

        $this->assertSame([$b->id, $a->id], $this->ids($this->build()->favorites));
    }

    public function test_a_favorite_the_user_can_no_longer_open_is_left_out(): void
    {
        $app = $this->openableApp();
        $this->user->favoriteApps()->attach($app);
        $app->update(['status' => App::STATUS_HIDDEN]);

        $page = $this->build();

        $this->assertSame([], $page->favorites);
        $this->assertSame(0, $page->appCount);
    }

    public function test_categories_follow_their_order_and_empty_ones_are_left_out(): void
    {
        $tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'sort_order' => 30]);
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'sort_order' => 10]);
        AppCategory::factory()->create(['name' => 'Compras', 'sort_order' => 20]);
        $this->openableApp(['app_category_id' => $tesoreria->id]);
        $this->openableApp(['app_category_id' => $ventas->id]);

        $names = array_map(fn (CategoryGroup $group) => $group->name, $this->build()->categories);

        $this->assertSame(['Ventas', 'Tesorería'], $names);
    }

    public function test_a_category_whose_apps_are_all_favorites_is_kept_empty(): void
    {
        // The page keeps it (hidden) so the favorite can move back without a reload.
        $ventas = AppCategory::factory()->create(['name' => 'Ventas']);
        $app = $this->openableApp(['app_category_id' => $ventas->id]);
        $this->user->favoriteApps()->attach($app);

        $page = $this->build();

        $this->assertCount(1, $page->categories);
        $this->assertSame([], $page->categories[0]->cards);
        $this->assertTrue($page->categories[0]->isEmpty());
    }

    public function test_each_status_gets_its_chip(): void
    {
        $active = $this->openableApp(['login_type' => 'microsoft']);
        $maintenance = $this->openableApp(['status' => App::STATUS_MAINTENANCE]);
        $failing = $this->openableApp(['login_type' => 'microsoft']);
        $failing->forceFill(['external_status_at' => now(), 'external_status' => 'down', 'external_status_message' => 'SAP no responde'])->save();
        $degraded = $this->openableApp();
        $degraded->forceFill(['external_status_at' => now(), 'external_status' => 'degraded'])->save();
        $failingInMaintenance = $this->openableApp(['status' => App::STATUS_MAINTENANCE]);
        $failingInMaintenance->forceFill(['external_status_at' => now(), 'external_status' => 'down'])->save();
        $own = $this->openableApp(['login_type' => 'own']);
        $local = App::factory()->local()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($local, $this->company);

        $cards = collect($this->build()->categories)->flatMap(fn (CategoryGroup $group) => $group->cards)->keyBy('id');

        $this->assertSame(AppCardStatus::Active, $cards[$active->id]->status);
        $this->assertSame('Activa', $cards[$active->id]->status->label());
        $this->assertSame(AppCardStatus::Maintenance, $cards[$maintenance->id]->status);
        $this->assertSame('En mantenimiento', $cards[$maintenance->id]->status->label());
        $this->assertSame(AppCardStatus::Failing, $cards[$failing->id]->status);
        $this->assertSame('Con fallas', $cards[$failing->id]->status->label());
        $this->assertSame('SAP no responde', $cards[$failing->id]->statusDetail);
        $this->assertSame('Estamos revisando', $cards[$degraded->id]->statusDetail);
        $this->assertSame(AppCardStatus::Maintenance, $cards[$failingInMaintenance->id]->status);
        $this->assertSame(AppCardStatus::OwnLogin, $cards[$own->id]->status);
        $this->assertSame('Inicio de sesión propio', $cards[$own->id]->status->label());
        $this->assertSame(AppCardStatus::Local, $cards[$local->id]->status);
        $this->assertSame('Instalada en tu equipo', $cards[$local->id]->status->label());
    }

    public function test_only_web_apps_outside_maintenance_open_in_a_new_tab(): void
    {
        $newTab = $this->openableApp(['open_in' => App::OPEN_NEW]);
        $sameTab = $this->openableApp(['open_in' => App::OPEN_SAME]);
        $maintenance = $this->openableApp(['open_in' => App::OPEN_NEW, 'status' => App::STATUS_MAINTENANCE]);
        $local = App::factory()->local()->forCompanies($this->company)->create(['open_in' => App::OPEN_NEW]);
        AppAccessRule::forCompany($local, $this->company);

        $cards = collect($this->build()->categories)->flatMap(fn (CategoryGroup $group) => $group->cards)->keyBy('id');

        $this->assertTrue($cards[$newTab->id]->opensInNewTab);
        $this->assertFalse($cards[$sameTab->id]->opensInNewTab);
        $this->assertFalse($cards[$maintenance->id]->opensInNewTab);
        $this->assertFalse($cards[$local->id]->opensInNewTab);
        $this->assertSame(route('apps.open', $newTab), $cards[$newTab->id]->href);
    }

    public function test_cards_carry_their_icons(): void
    {
        $app = $this->openableApp(['icon_path' => 'icons/apps/cartera.svg', 'icon_dark_path' => 'icons/apps/cartera-dark.svg']);
        $plain = $this->openableApp(['name' => 'sin ícono', 'icon_path' => null]);

        $cards = collect($this->build()->categories)->flatMap(fn (CategoryGroup $group) => $group->cards)->keyBy('id');

        $this->assertSame(asset('icons/apps/cartera.svg'), $cards[$app->id]->iconUrl);
        $this->assertSame(asset('icons/apps/cartera-dark.svg'), $cards[$app->id]->iconDarkUrl);
        $this->assertNull($cards[$plain->id]->iconUrl);
        $this->assertSame('S', $cards[$plain->id]->initial);
    }

    public function test_recent_apps_are_listed_newest_first(): void
    {
        $a = $this->openableApp();
        $b = $this->openableApp();
        $this->launch($a, 30);
        $this->launch($b, 10);

        $this->assertSame([$b->id, $a->id], $this->ids($this->build()->recents));
    }

    public function test_search_filters_by_name_and_description_without_accents(): void
    {
        $camaras = $this->openableApp(['name' => 'Cámaras', 'description' => 'Monitoreo de seguridad']);
        $cartera = $this->openableApp(['name' => 'Visualizador de cartera', 'description' => 'Cartera por cliente']);
        $this->openableApp(['name' => 'Toma Pedidos SAP', 'description' => 'Pedidos de venta']);
        $this->user->favoriteApps()->attach($cartera);

        $page = $this->build('CAMARAS');
        $this->assertSame([$camaras->id], $this->allCardIds($page));
        $this->assertSame(1, $page->resultCount);
        $this->assertTrue($page->isSearching());
        $this->assertSame(3, $page->appCount, 'the greeting still counts every app');

        $this->assertSame([$cartera->id], $this->allCardIds($this->build('cliente')));
        $this->assertSame([], $this->allCardIds($this->build('nómina')));
    }

    public function test_searching_hides_recents_and_empty_groups(): void
    {
        $ventas = AppCategory::factory()->create(['name' => 'Ventas']);
        $app = $this->openableApp(['name' => 'Cámaras']);
        $this->openableApp(['name' => 'Pedidos', 'app_category_id' => $ventas->id]);
        $this->launch($app, 5);

        $page = $this->build('camaras');

        $this->assertSame([], $page->recents);
        $this->assertCount(1, $page->categories);
    }

    public function test_the_quick_links_include_the_company_links(): void
    {
        $this->company->update(['quick_links' => [['label' => 'SharePoint', 'url' => 'https://segurex.sharepoint.com']]]);

        $labels = array_map(fn ($link) => $link->label, $this->build()->quickLinks);

        $this->assertSame(['Outlook', 'Teams', 'OneDrive', 'SharePoint'], $labels);
    }

    public function test_admins_and_announcement_editors_may_administer(): void
    {
        $this->seed(RoleSeeder::class);
        $this->assertFalse($this->build()->canAdminister);

        foreach (['super_admin', 'portal_admin', 'announcements_editor'] as $role) {
            $user = User::factory()->microsoft($this->company)->create();
            $user->assignRole($role);
            $this->assertTrue(app(HomePageBuilder::class)->build($user->fresh())->canAdminister, $role);
        }
    }

    public function test_a_user_without_a_company_gets_an_empty_page(): void
    {
        $user = User::factory()->create(['name' => 'Luis Gómez', 'company_id' => null]);

        $page = app(HomePageBuilder::class)->build($user);

        $this->assertFalse($page->hasApps());
        $this->assertSame(config('app.name'), $page->companyName);
        $this->assertSame('LG', $page->userInitials);
        $this->assertCount(3, $page->quickLinks);
    }

    public function test_the_company_mark_comes_from_one_place(): void
    {
        $company = new Company(['name' => 'Soluciones y Derivados', 'short_name' => 'derivados']);

        $this->assertSame('Soluciones y Derivados', Company::displayName($company));
        $this->assertSame('D', Company::displayInitial($company));
        $this->assertSame(config('app.name'), Company::displayName(null));
        $this->assertSame(mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)), Company::displayInitial(null));

        // The header draws the mark with <x-brand-logo>: the page no longer
        // carries its own copy of the initial and the icon.
        $this->assertFalse(property_exists(HomePage::class, 'companyInitial'));
        $this->assertFalse(property_exists(HomePage::class, 'companyIconUrl'));
    }

    private function build(?string $query = null, ?CarbonImmutable $now = null): HomePage
    {
        return app(HomePageBuilder::class)->build($this->user->fresh(), $query, $now);
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

    private function launch(App $app, int $minutesAgo): void
    {
        AppLaunch::create([
            'user_id' => $this->user->id,
            'app_id' => $app->id,
            'company_id' => $this->company->id,
            'device' => 'desktop',
            'launched_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @param  list<AppCard>  $cards
     * @return list<int>
     */
    private function ids(array $cards): array
    {
        return array_map(fn (AppCard $card) => $card->id, $cards);
    }

    /**
     * @return list<int>
     */
    private function allCardIds(HomePage $page): array
    {
        $ids = $this->ids($page->favorites);

        foreach ($page->categories as $group) {
            $ids = [...$ids, ...$this->ids($group->cards)];
        }

        sort($ids);

        return $ids;
    }
}
