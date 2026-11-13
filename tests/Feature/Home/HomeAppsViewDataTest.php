<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use App\View\Home\AppCard;
use App\View\Home\AppsView;
use App\View\Home\CategoryGroup;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The data of each view of «Mis aplicaciones» (odd/tasks/vistas-apps.md
 * V2): «Categorías» stays as it was; «Todas», «Lista» and «Íconos» get one
 * list from A to Z, with no Favoritos or Recientes, and no query more.
 */
class HomeAppsViewDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private AppCategory $ventas;

    private AppCategory $tesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
        $this->ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 10]);
        $this->tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria', 'sort_order' => 20]);
    }

    public function test_an_account_that_never_chose_gets_the_categories_view(): void
    {
        $this->openableApp(['name' => 'Cartera']);

        $page = $this->build();

        $this->assertSame(AppsView::Categories, $page->appsView);
        $this->assertSame([], $page->apps);
        $this->assertNotSame([], $page->categories);
    }

    public function test_a_stored_value_no_longer_offered_falls_back_to_categories(): void
    {
        $this->openableApp(['name' => 'Cartera']);
        $this->choose('mosaic');

        $this->assertSame(AppsView::Categories, $this->build()->appsView);
    }

    public function test_choosing_categories_gives_the_same_page_as_never_choosing(): void
    {
        $this->catalogWithFavoritesAndRecents();
        $never = $this->build();

        $this->choose('categories');
        $chosen = $this->build();

        $this->assertSame(AppsView::Categories, $chosen->appsView);
        $this->assertSame($this->ids($never->favorites), $this->ids($chosen->favorites));
        $this->assertSame($this->ids($never->recents), $this->ids($chosen->recents));
        $this->assertSame($this->categoryIds($never), $this->categoryIds($chosen));
        $this->assertSame($never->resultCount, $chosen->resultCount);
        $this->assertSame([], $chosen->apps);
    }

    #[DataProvider('flatViews')]
    public function test_a_flat_view_lists_every_app_once_from_a_to_z(string $view): void
    {
        foreach (['zeta', 'Ámbito', 'beta', 'App 10', 'Alfa', 'Cámaras', 'App 9', 'Cartera'] as $n => $name) {
            $this->openableApp(['name' => $name, 'app_category_id' => $n % 2 === 0 ? $this->ventas->id : $this->tesoreria->id]);
        }
        $this->choose($view);

        $page = $this->build();

        $this->assertSame(AppsView::from($view), $page->appsView);
        $this->assertSame(
            ['Alfa', 'Ámbito', 'App 9', 'App 10', 'beta', 'Cámaras', 'Cartera', 'zeta'],
            array_map(fn (AppCard $card) => $card->name, $page->apps),
        );
        $this->assertSame([], $page->categories);
    }

    #[DataProvider('flatViews')]
    public function test_a_flat_view_keeps_favorites_in_place_and_shows_no_recents(string $view): void
    {
        [$favorite] = $this->catalogWithFavoritesAndRecents();
        $this->choose($view);

        $page = $this->build();

        $this->assertSame([], $page->favorites);
        $this->assertSame([], $page->recents);
        $this->assertSame(['Bodega', 'Cartera', 'Pedidos'], array_map(fn (AppCard $card) => $card->name, $page->apps));

        $byId = array_column(array_map(fn (AppCard $card) => ['id' => $card->id, 'favorite' => $card->favorite], $page->apps), 'favorite', 'id');
        $this->assertTrue($byId[$favorite->id]);
        $this->assertSame(1, count(array_filter($byId)));
    }

    #[DataProvider('everyView')]
    public function test_the_search_filters_the_same_way_in_every_view(string $view): void
    {
        $this->openableApp(['name' => 'Cámaras', 'description' => 'Monitoreo de seguridad', 'app_category_id' => $this->ventas->id]);
        $this->openableApp(['name' => 'Toma Pedidos SAP', 'description' => 'Pedidos de venta', 'app_category_id' => $this->tesoreria->id]);
        $this->openableApp(['name' => 'Cartera', 'description' => 'Cámaras de cobro', 'app_category_id' => $this->tesoreria->id]);
        $this->choose($view);

        $page = $this->build('camaras');

        $this->assertEqualsCanonicalizing(['Cámaras', 'Cartera'], $this->shownNames($page));
        $this->assertSame(2, $page->resultCount);
        $this->assertSame('2 resultados', $page->resultSummary());

        $none = $this->build('nómina');
        $this->assertSame([], $this->shownNames($none));
        $this->assertSame(0, $none->resultCount);
    }

    #[DataProvider('flatViews')]
    public function test_the_sections_menu_of_a_flat_view_points_at_the_list(string $view): void
    {
        $this->catalogWithFavoritesAndRecents();
        $this->choose($view);

        $hrefs = array_column($this->build()->sections(), 'href');

        $this->assertContains('#aplicaciones', $hrefs);
        $this->assertNotContains('#favoritos', $hrefs);
        $this->assertNotContains('#recientes', $hrefs);
    }

    #[DataProvider('everyView')]
    public function test_every_view_stays_within_the_query_budget_whatever_the_catalog(string $view): void
    {
        $this->choose($view);
        $this->openableApp();
        $small = $this->countQueries();

        foreach (range(1, 12) as $i) {
            $app = $this->openableApp(['app_category_id' => $i % 2 === 0 ? $this->ventas->id : $this->tesoreria->id]);
            if ($i % 3 === 0) {
                $this->user->favoriteApps()->attach($app);
            }
            $this->launch($app, $i);
        }

        $large = $this->countQueries();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(HomeQueryBudget::MAX, $large);
    }

    public function test_the_preference_itself_adds_no_query(): void
    {
        $this->catalogWithFavoritesAndRecents();
        $categories = $this->countQueries();

        foreach (['categories', 'all', 'list', 'launcher'] as $view) {
            $this->choose($view);
            // The flat views skip Recientes, so they may run fewer, never more.
            $this->assertLessThanOrEqual($categories, $this->countQueries(), $view);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function flatViews(): array
    {
        return [
            'all' => ['all'],
            'list' => ['list'],
            'launcher' => ['launcher'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyView(): array
    {
        return ['categories' => ['categories'], ...self::flatViews()];
    }

    /**
     * Three apps in two categories, «Pedidos» a favorite, two launched.
     *
     * @return array{0: App}
     */
    private function catalogWithFavoritesAndRecents(): array
    {
        $bodega = $this->openableApp(['name' => 'Bodega', 'app_category_id' => $this->ventas->id]);
        $favorite = $this->openableApp(['name' => 'Pedidos', 'app_category_id' => $this->ventas->id]);
        $cartera = $this->openableApp(['name' => 'Cartera', 'app_category_id' => $this->tesoreria->id]);
        $this->user->favoriteApps()->attach($favorite);
        $this->launch($cartera, 5);
        $this->launch($bodega, 10);

        return [$favorite];
    }

    private function choose(string $view): void
    {
        $this->user->forceFill(['apps_view' => $view])->save();
    }

    private function build(?string $query = null): HomePage
    {
        return app(HomePageBuilder::class)->build($this->user->fresh(), $query);
    }

    /**
     * The names a page shows, whatever the view.
     *
     * @return list<string>
     */
    private function shownNames(HomePage $page): array
    {
        $cards = $page->appsView->isFlat()
            ? $page->apps
            : [...$page->favorites, ...array_merge(...array_map(fn (CategoryGroup $group) => $group->cards, $page->categories))];

        return array_map(fn (AppCard $card) => $card->name, $cards);
    }

    /**
     * @return list<array{0: string, 1: list<int>}>
     */
    private function categoryIds(HomePage $page): array
    {
        return array_map(fn (CategoryGroup $group) => [$group->slug, $this->ids($group->cards)], $page->categories);
    }

    /**
     * @param  list<AppCard>  $cards
     * @return list<int>
     */
    private function ids(array $cards): array
    {
        return array_map(fn (AppCard $card) => $card->id, $cards);
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
     * @param  array<string, mixed>  $attributes
     */
    private function openableApp(array $attributes = []): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }
}
