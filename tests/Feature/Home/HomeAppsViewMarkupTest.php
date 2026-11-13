<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMAttr;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * The markup of the views of «Mis aplicaciones» (odd/tasks/vistas-apps.md
 * V3): the selector in the card head, a plain form that works without
 * JavaScript, and the cards of «Todas», «Lista» and «Íconos».
 */
class HomeAppsViewMarkupTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    /**
     * The body of the card in «Categorías» as main rendered it before the
     * views existed (82cfdae), for catalog() and cardBody().
     */
    private const FIXTURES = __DIR__.'/fixtures';

    private const SELECTOR = '//section[@id="mis-aplicaciones"]//form[@action="http://localhost/inicio/vista"]';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
    }

    /**
     * An account that never chose, and one that chose «Categorías», see the
     * cards exactly as before the views (whitespace aside).
     */
    #[DataProvider('categoriesSearches')]
    public function test_the_categories_view_renders_the_cards_as_before(?string $stored, string $suffix, string $fixture): void
    {
        $this->catalog();
        $this->choose($stored);

        $this->assertSame(
            trim((string) file_get_contents(self::FIXTURES.'/'.$fixture)),
            $this->cardBody($this->home($suffix)),
        );
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function categoriesSearches(): array
    {
        return [
            'never chosen' => [null, '', 'apps-card-categories.html'],
            'chosen' => ['categories', '', 'apps-card-categories.html'],
            'never chosen, searching' => [null, '?q=a', 'apps-card-categories-search.html'],
            'chosen, searching' => ['categories', '?q=a', 'apps-card-categories-search.html'],
        ];
    }

    public function test_the_selector_is_a_put_form_with_the_four_views(): void
    {
        $this->catalog();
        $xpath = $this->xpath($this->home());

        $forms = $xpath->query(self::SELECTOR.'[@method="POST"]');
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_method"][@value="PUT"]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[@name="q"]', $form)->length);
        // In the card head, beside the title.
        $this->assertSame(1, $xpath->query('ancestor::div[contains(concat(" ", @class, " "), " g-card__head ")]', $form)->length);

        $group = $xpath->query('.//*[contains(concat(" ", @class, " "), " g-seg ")][@role="group"][@aria-label="Vista de aplicaciones"]', $form);
        $this->assertSame(1, $group->length);

        $buttons = iterator_to_array($xpath->query('.//button[@type="submit"][@name="view"]', $group->item(0)));
        $this->assertSame(['categories', 'all', 'list', 'launcher'], array_map(fn (DOMElement $button) => $button->getAttribute('value'), $buttons));
        $this->assertSame(['Categorías', 'Todas', 'Lista', 'Íconos'], array_map(fn (DOMElement $button) => trim($button->textContent), $buttons));
    }

    #[DataProvider('everyView')]
    public function test_only_the_chosen_view_is_pressed(?string $stored, string $pressed): void
    {
        $this->catalog();
        $this->choose($stored);

        $xpath = $this->xpath($this->home());

        $this->assertSame([$pressed], array_map(
            fn (DOMElement $button) => $button->getAttribute('value'),
            iterator_to_array($xpath->query(self::SELECTOR.'//button[@aria-pressed="true"]')),
        ));
        $this->assertSame(3, $xpath->query(self::SELECTOR.'//button[@aria-pressed="false"]')->length);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function everyView(): array
    {
        return [
            'never chosen' => [null, 'categories'],
            'categories' => ['categories', 'categories'],
            'all' => ['all', 'all'],
            'list' => ['list', 'list'],
            'launcher' => ['launcher', 'launcher'],
        ];
    }

    public function test_the_selector_keeps_the_search(): void
    {
        $this->catalog();

        $xpath = $this->xpath($this->home('?q=car'));

        $this->assertSame(1, $xpath->query(self::SELECTOR.'//input[@type="hidden"][@name="q"][@value="car"]')->length);
    }

    public function test_an_account_without_apps_gets_no_selector(): void
    {
        $this->assertSame(0, $this->xpath($this->home())->query(self::SELECTOR)->length);
    }

    /**
     * @param  list<string>  $classes
     */
    #[DataProvider('flatViews')]
    public function test_a_flat_view_renders_one_list_without_favorites_or_recents(string $view, string $list, array $classes): void
    {
        $this->catalog();
        $this->choose($view);

        $response = $this->home();
        $xpath = $this->xpath($response);

        $lists = $xpath->query('//section[@id="mis-aplicaciones"]//ul[@data-list]');
        $this->assertSame(1, $lists->length);
        $this->assertSame('aplicaciones', $lists->item(0)->getAttribute('id'));
        $this->assertSame($list, $lists->item(0)->getAttribute('class'));

        $items = $xpath->query('./li[@data-app]', $lists->item(0));
        $this->assertSame(6, $items->length);
        foreach ($items as $item) {
            $this->assertSame($classes, array_values(array_diff(explode(' ', $item->getAttribute('class')), ['g-app--failing'])));
        }

        $this->assertNoElement($response, 'id', 'favoritos');
        $this->assertNoElement($response, 'id', 'recientes');
        $this->assertSame(0, $xpath->query('//section[@data-category]')->length);
        $this->assertNotContains('#favoritos', $this->sectionLinks($response));
        $this->assertContains('#aplicaciones', $this->sectionLinks($response));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function flatViews(): array
    {
        return [
            'all' => ['all', 'g-apps scroll-mt-6', ['g-tile', 'g-app', 'min-w-0']],
            'list' => ['list', 'g-apps g-apps--list scroll-mt-6', ['g-tile', 'g-app', 'min-w-0', 'g-app--row']],
            'launcher' => ['launcher', 'g-apps g-apps--launcher scroll-mt-6', ['g-tile', 'g-app', 'min-w-0', 'g-app--icon']],
        ];
    }

    #[DataProvider('flatViews')]
    public function test_a_flat_view_goes_from_a_to_z_with_every_star_in_place(string $view): void
    {
        $this->catalog();
        $this->choose($view);

        $xpath = $this->xpath($this->home());
        $items = iterator_to_array($xpath->query('//ul[@id="aplicaciones"]/li'));

        $this->assertSame(
            ['bancos', 'camaras', 'cartera', 'nomina', 'portafolio', 'toma-pedidos'],
            array_map(fn (DOMElement $item) => $item->getAttribute('data-app'), $items),
        );

        foreach ($items as $item) {
            $stars = $xpath->query('.//form[@data-favorite]//button[@aria-pressed]', $item);
            $this->assertSame(1, $stars->length);
            $this->assertSame($item->getAttribute('data-app') === 'cartera' ? 'true' : 'false', $stars->item(0)->getAttribute('aria-pressed'));
        }
    }

    public function test_the_list_shows_the_description_and_the_status_of_each_row(): void
    {
        $this->catalog();
        $this->choose('list');

        $xpath = $this->xpath($this->home());
        $row = fn (string $slug) => (string) preg_replace('/\s+/u', ' ', trim($xpath->query('//li[@id="app-'.$slug.'"]')->item(0)->textContent));

        $this->assertStringContainsString('Cartera Cuentas por cobrar Activa', $row('cartera'));
        $this->assertStringContainsString('Cámaras Monitoreo de seguridad En mantenimiento', $row('camaras'));
        $this->assertStringContainsString('Con fallas · SAP no responde', $row('bancos'));
        $this->assertStringContainsString('Inicio de sesión propio', $row('portafolio'));
        $this->assertStringContainsString('Instalada en tu equipo', $row('nomina'));
        $this->assertSame(6, $xpath->query('//ul[@id="aplicaciones"]/li/div[@class="g-app__status"]')->length);
        $this->assertSame(1, $xpath->query('//li[@id="app-toma-pedidos"]//img[@width="40"][@height="40"]')->length);
    }

    /**
     * A long name wraps to two lines instead of losing its ending at 375 px;
     * the description stays on one.
     */
    public function test_the_list_wraps_a_long_name_and_keeps_the_description_on_one_line(): void
    {
        $this->catalog();
        $this->choose('list');

        $xpath = $this->xpath($this->home());
        $name = $xpath->query('//li[@id="app-cartera"]/div[@class="g-app__text"]/a')->item(0);
        $description = $xpath->query('//li[@id="app-cartera"]/div[@class="g-app__text"]/p')->item(0);

        $this->assertEqualsCanonicalizing(['line-clamp-2', 'break-words'], array_values(array_intersect(['line-clamp-2', 'break-words', 'truncate'], explode(' ', $name->getAttribute('class')))));
        $this->assertContains('truncate', explode(' ', $description->getAttribute('class')));
    }

    /**
     * «Íconos» marks every status but «Activa» with a round icon chip of
     * 24 px (glass.css), its text for screen readers and as a tooltip; no
     * text badge, so every tile keeps the same height.
     */
    public function test_the_launcher_marks_each_status_with_a_small_icon(): void
    {
        $this->catalog();
        $this->choose('launcher');

        $xpath = $this->xpath($this->home());

        $this->assertSame(0, $xpath->query('//ul[@id="aplicaciones"]/li/div[@class="g-app__status"]')->length);

        $marks = [];
        foreach ($xpath->query('//ul[@id="aplicaciones"]/li/span[contains(concat(" ", @class, " "), " g-app__mark ")]') as $mark) {
            $this->assertSame(1, $xpath->query('./*[name()="svg"][@aria-hidden="true"]', $mark)->length);
            $this->assertSame($mark->getAttribute('title'), trim($xpath->query('./span[@class="sr-only"]', $mark)->item(0)->textContent));
            $marks[$mark->parentNode->getAttribute('data-app')] = [$mark->getAttribute('data-mark'), $mark->getAttribute('title')];
        }

        $this->assertSame([
            'bancos' => ['warning', 'Con fallas · SAP no responde'],
            'camaras' => ['wrench', 'En mantenimiento'],
            'nomina' => ['monitor', 'Instalada en tu equipo'],
            'portafolio' => ['lock', 'Inicio de sesión propio'],
        ], $marks);
        $this->assertStringNotContainsString('Activa', $xpath->query('//li[@id="app-cartera"]')->item(0)->textContent);
    }

    /**
     * The 32 px star of «Íconos» keeps the 44 px touch target with a
     * pseudo-element around it.
     */
    public function test_the_launcher_star_keeps_a_touch_sized_hit_area(): void
    {
        $this->catalog();
        $this->choose('launcher');

        $button = $this->xpath($this->home())->query('//li[@id="app-cartera"]//form[@data-favorite]//button[@aria-label="Marcar Cartera como favorita"]');

        $this->assertSame(1, $button->length);
        $classes = explode(' ', $button->item(0)->getAttribute('class'));
        foreach (['relative', 'size-8', 'before:absolute', 'before:-inset-1.5'] as $class) {
            $this->assertContains($class, $classes);
        }
        $this->assertNotContains('size-touch', $classes);
    }

    /**
     * Every tile of «Íconos» is as tall as the tallest one, and the status
     * mark is at most 24 px.
     */
    public function test_the_launcher_rows_share_one_height(): void
    {
        $css = (string) file_get_contents(resource_path('css/glass.css'));

        $this->assertMatchesRegularExpression('/\.g-apps--launcher \{[^}]*grid-auto-rows: 1fr;/', $css);
        $this->assertMatchesRegularExpression('/\.g-app--icon > \.g-app__mark \{[^}]*width: 24px;[^}]*height: 24px;/', $css);
    }

    #[DataProvider('everyView')]
    public function test_the_search_narrows_every_view_the_same_way(?string $stored): void
    {
        $this->catalog();
        $this->choose($stored);

        $xpath = $this->xpath($this->home('?q=camaras')->assertSeeText('1 resultado'));

        $this->assertSame(['camaras'], array_map(fn (DOMElement $item) => $item->getAttribute('data-app'), iterator_to_array($xpath->query('//li[@data-app]'))));
        $this->assertSame(1, $xpath->query('//li[@id="app-camaras"]//form[@data-favorite]//input[@name="q"][@value="camaras"]')->length);

        $this->home('?q=n%C3%B3mina%20sap')->assertSeeText('No encontramos aplicaciones para «nómina sap».');
    }

    #[DataProvider('everyView')]
    public function test_ids_stay_unique_in_every_view(?string $stored): void
    {
        $this->catalog();
        $this->choose($stored);

        $ids = array_map(fn (DOMAttr $id) => $id->value, iterator_to_array($this->xpath($this->home())->query('//@id')));

        $this->assertNotEmpty($ids);
        $this->assertSame([], array_keys(array_filter(array_count_values($ids), fn (int $count) => $count > 1)));
    }

    #[DataProvider('everyView')]
    public function test_stored_names_and_descriptions_are_escaped_in_every_view(?string $stored): void
    {
        $this->openableApp([
            'slug' => 'peligrosa',
            'name' => '<script>alert(1)</script> "Cartera"',
            'description' => '<img src=x onerror=alert(2)> & más',
        ]);
        $this->choose($stored);

        $html = $this->home()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;Cartera&quot;', $html);

        // «Íconos» shows no descriptions.
        if ($stored !== 'launcher') {
            $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt; &amp; más', $html);
        }
    }

    /**
     * The categories, recents and favorites of today's home, every status,
     * icons with a dark variant; no randomness, so the markup is stable.
     */
    private function catalog(): void
    {
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'sort_order' => 10]);
        $tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria', 'sort_order' => 20]);

        $apps = [
            ['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'description' => 'Pedidos de venta en ruta', 'app_category_id' => $ventas->id, 'login_type' => 'microsoft', 'icon_path' => 'icons/apps/pedidos.svg', 'icon_dark_path' => 'icons/apps/pedidos-dark.svg'],
            ['slug' => 'portafolio', 'name' => 'Portafolio', 'description' => 'Precios y fichas de producto', 'app_category_id' => $ventas->id, 'login_type' => 'own', 'open_in' => App::OPEN_SAME],
            ['slug' => 'camaras', 'name' => 'Cámaras', 'description' => 'Monitoreo de seguridad', 'app_category_id' => $ventas->id, 'login_type' => 'microsoft', 'status' => App::STATUS_MAINTENANCE],
            ['slug' => 'cartera', 'name' => 'Cartera', 'description' => 'Cuentas por cobrar', 'app_category_id' => $tesoreria->id, 'login_type' => 'microsoft', 'accent_color' => '#0F766E'],
            ['slug' => 'bancos', 'name' => 'Bancos', 'description' => 'Conciliación bancaria', 'app_category_id' => $tesoreria->id, 'login_type' => 'microsoft'],
        ];

        $created = [];
        foreach ($apps as $attributes) {
            $created[$attributes['slug']] = $this->openableApp(['url' => 'https://'.$attributes['slug'].'.segurex.com.co', ...$attributes]);
        }

        $created['bancos']->forceFill(['external_status_at' => now(), 'external_status' => 'down', 'external_status_message' => 'SAP no responde'])->save();

        $local = App::factory()->local()->forCompanies($this->company)->create(['slug' => 'nomina', 'name' => 'Nómina', 'description' => 'Liquidación de nómina', 'app_category_id' => $tesoreria->id]);
        AppAccessRule::forCompany($local, $this->company);

        $this->user->favoriteApps()->attach($created['cartera']);

        foreach (['portafolio' => 5, 'bancos' => 10] as $slug => $minutes) {
            AppLaunch::create([
                'user_id' => $this->user->id,
                'app_id' => $created[$slug]->id,
                'company_id' => $this->company->id,
                'device' => 'desktop',
                'launched_at' => now()->subMinutes($minutes),
            ]);
        }
    }

    private function choose(?string $view): void
    {
        if ($view !== null) {
            $this->user->forceFill(['apps_view' => $view])->save();
        }
    }

    /**
     * The body of «Mis aplicaciones» with a fixed CSRF token, whitespace
     * collapsed and none kept next to a tag.
     */
    private function cardBody(TestResponse $response): string
    {
        $xpath = $this->xpath($response);
        $nodes = $xpath->query('//section[@id="mis-aplicaciones"]/div[contains(concat(" ", @class, " "), " g-card__body ")]');
        $this->assertSame(1, $nodes->length);

        $html = (string) $xpath->document->saveHTML($nodes->item(0));
        $html = (string) preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="token"', $html);

        return trim((string) preg_replace(['/\s+/', '/>\s+/', '/\s+</'], [' ', '>', '<'], $html));
    }
}
