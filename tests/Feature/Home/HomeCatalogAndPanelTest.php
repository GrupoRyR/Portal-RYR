<?php

namespace Tests\Feature\Home;

use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * «Catálogos y categorías» (App\Products\ProductBoard) and the «Panel
 * gerencial» (App\Management\ManagementPanel) of the home screen
 * (odd/tasks/inicio-bloques.md H2).
 */
class HomeCatalogAndPanelTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00', 'America/Bogota'));
        $this->company = Company::factory()->withManagementGroup(self::GROUP)->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana Pérez']);
        $this->openableApp();
    }

    // --- Catálogos y categorías -----------------------------------------------

    public function test_there_is_no_catalog_without_published_products(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);
        Product::factory()->create(['company_id' => $this->company->id, 'product_category_id' => $category->id]);

        $response = $this->home();

        $this->assertNoElement($response, 'id', 'catalogos');
        $this->assertNotContains('#catalogos', $this->sectionLinks($response));
    }

    public function test_the_catalog_shows_the_companys_published_products_by_category(): void
    {
        $smart = $this->category('Línea smart', 1);
        $residential = $this->category('Residencial', 2);
        $this->category('Sin publicar', 3, products: []);
        $this->product($smart, 'Cerradura digital S1', ['summary' => 'Huella, clave y tarjeta']);
        $this->product($residential, 'Cerrojo de seguridad');
        $this->product($residential, 'Borrador', ['published' => false]);

        $other = Company::factory()->create();
        $foreign = ProductCategory::factory()->create(['company_id' => $other->id, 'name' => 'Hipoclorito']);
        Product::factory()->published()->create(['company_id' => $other->id, 'product_category_id' => $foreign->id, 'name' => 'Producto ajeno']);

        $response = $this->home();
        $catalog = $this->element($response, 'id', 'catalogos');

        $this->assertStringContainsString('Catálogos y categorías', $catalog);
        $this->assertStringContainsString('Cerradura digital S1', $catalog);
        $this->assertStringContainsString('Huella, clave y tarjeta', $catalog);
        $this->assertStringContainsString('Cerrojo de seguridad', $catalog);
        $this->assertStringNotContainsString('Borrador', $catalog);
        $this->assertStringNotContainsString('Sin publicar', $catalog);
        $this->assertStringNotContainsString('Hipoclorito', $catalog);
        $this->assertStringNotContainsString('Producto ajeno', $catalog);
        $this->assertContains('#catalogos', $this->sectionLinks($response));

        $filters = $this->xpath($response)->query('//*[@data-catalog-filter]');
        $this->assertSame(['Todas', 'Línea smart', 'Residencial'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($filters)));
        $this->assertStringContainsString('aria-current="true"', $this->element($response, 'data-catalog-filter', 'todas'));
    }

    public function test_product_photos_load_lazily_with_a_fixed_size_and_links_open_outside(): void
    {
        $category = $this->category('Línea smart', 1);
        $withPhoto = $this->product($category, 'Con foto', ['photo_path' => 'products/1/foto.jpg', 'link_url' => 'https://segurex.com.co/ficha.pdf']);
        $withoutPhoto = $this->product($category, 'Sin foto');

        $response = $this->home();
        $withPhoto = $this->element($response, 'data-product', (string) $withPhoto->id);

        $this->assertStringContainsString('loading="lazy"', $withPhoto);
        $this->assertStringContainsString('width="', $withPhoto);
        $this->assertStringContainsString('height="', $withPhoto);
        $this->assertStringContainsString('products/1/foto.jpg', $withPhoto);
        $this->assertStringContainsString('href="https://segurex.com.co/ficha.pdf"', $withPhoto);
        $this->assertStringContainsString('rel="noopener noreferrer"', $withPhoto);

        $withoutPhoto = $this->element($response, 'data-product', (string) $withoutPhoto->id);
        $this->assertStringNotContainsString('<img', $withoutPhoto);
        $this->assertStringNotContainsString('href=', $withoutPhoto);
    }

    public function test_the_category_in_the_address_filters_the_products_without_javascript(): void
    {
        $smart = $this->category('Línea smart', 1);
        $residential = $this->category('Residencial', 2);
        $lock = $this->product($smart, 'Cerradura digital S1');
        $bolt = $this->product($residential, 'Cerrojo de seguridad');

        $response = $this->home('?catalogo='.$residential->id);

        $this->assertStringContainsString('aria-current="true"', $this->element($response, 'data-catalog-filter', (string) $residential->id));
        $this->assertStringNotContainsString('aria-current', $this->element($response, 'data-catalog-filter', 'todas'));
        $this->assertStringContainsString(' hidden', $this->openingTag($this->element($response, 'data-product', (string) $lock->id)));
        $this->assertStringNotContainsString(' hidden', $this->openingTag($this->element($response, 'data-product', (string) $bolt->id)));
    }

    public function test_another_companys_category_in_the_address_shows_them_all(): void
    {
        $smart = $this->category('Línea smart', 1);
        $lock = $this->product($smart, 'Cerradura digital S1');
        $foreign = ProductCategory::factory()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->home('?catalogo='.$foreign->id);

        $this->assertStringContainsString('aria-current="true"', $this->element($response, 'data-catalog-filter', 'todas'));
        $this->assertStringNotContainsString(' hidden', $this->openingTag($this->element($response, 'data-product', (string) $lock->id)));
    }

    public function test_a_server_search_leaves_the_catalog_out(): void
    {
        $this->product($this->category('Línea smart', 1), 'Cerradura digital S1');

        $this->assertNoElement($this->home('?q=zzz'), 'id', 'catalogos');
    }

    // --- Panel gerencial -------------------------------------------------------

    public function test_the_panel_is_only_for_the_companys_management(): void
    {
        $this->figures();

        $this->assertNoElement($this->home(), 'id', 'panel-gerencial');

        $admin = User::factory()->microsoft($this->company)->create();
        $admin->assignRole('portal_admin');
        $this->assertNoElement($this->home(as: $admin), 'id', 'panel-gerencial');

        // The role without the group (or the group without the role) is not enough.
        $roleOnly = User::factory()->microsoft($this->company)->create();
        $roleOnly->assignRole(MicrosoftLogin::MANAGEMENT);
        $this->assertNoElement($this->home(as: $roleOnly), 'id', 'panel-gerencial');
    }

    public function test_management_sees_the_figures_of_its_company(): void
    {
        $this->figures();
        $other = Company::factory()->withManagementGroup('bbbbbbbb-0000-4000-8000-000000000002')->create();
        CompanyKpi::factory()->create(['company_id' => $other->id, 'key' => 'ajena', 'label' => 'Cifra ajena']);

        $response = $this->home(as: $this->manager());
        $panel = $this->element($response, 'id', 'panel-gerencial');

        $this->assertStringContainsString('Panel gerencial', $panel);
        $this->assertStringContainsString('Solo Gerencia', $panel);
        $this->assertStringContainsString('Octubre, al cierre de ayer', $panel);
        $this->assertStringContainsString('Ventas del mes', $panel);
        $this->assertStringContainsString('$1.250', $panel);
        $this->assertStringContainsString('millones', $panel);
        $this->assertStringContainsString('83 % de la meta', $panel);
        $this->assertStringContainsString('aria-valuenow="83"', $panel);
        $this->assertStringContainsString('Meta: $1.500 millones', $panel);
        $this->assertStringContainsString('Sin IVA', $panel);
        $this->assertStringContainsString('Cartera vencida', $panel);
        $this->assertStringContainsString('12,5 %', $panel);
        $this->assertStringContainsString('Pedidos atrasados', $panel);
        $this->assertStringContainsString('Fuente: SAP Business One', $panel);
        $this->assertStringNotContainsString('Cifra ajena', $panel);
        $this->assertContains('#panel-gerencial', $this->sectionLinks($response));
    }

    public function test_a_figure_past_its_goal_fills_the_meter_and_says_so(): void
    {
        CompanyKpi::factory()->create(['company_id' => $this->company->id, 'value' => 1800000000, 'goal' => 1500000000]);

        $panel = $this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial');

        $this->assertStringContainsString('120 % de la meta', $panel);
        $this->assertStringContainsString('aria-valuenow="100"', $panel);
    }

    public function test_an_older_closing_names_its_day(): void
    {
        $this->figures(['as_of' => CarbonImmutable::parse('2026-11-02 23:59', 'America/Bogota')]);

        $panel = $this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial');

        $this->assertStringContainsString('Octubre, al cierre del 2 de noviembre', $panel);
    }

    /**
     * The service worker (resources/js/sw.js) saves the home page for
     * offline use without the elements marked `data-offline-omit`
     * (`OFFLINE_OMIT`): the panel and its «Secciones» entry. Figures never
     * stay on the device, so losing «Gerencia» leaves none offline. This
     * runs the worker's own pattern on the real page.
     */
    public function test_the_saved_offline_copy_leaves_the_panel_out(): void
    {
        $this->figures();
        $worker = $this->get('/sw.js')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~^const OFFLINE_OMIT = /(.+)/g;$~m', $worker, $match), 'The worker defines OFFLINE_OMIT.');
        $this->assertStringContainsString(".replace(OFFLINE_OMIT, '')", $worker);

        $html = $this->home(as: $this->manager())->getContent();
        $saved = (string) preg_replace('/'.$match[1].'/', '', $html);

        $this->assertStringContainsString('Ventas del mes', $html);
        $this->assertStringNotContainsString('panel-gerencial', $saved);
        $this->assertStringNotContainsString('Ventas del mes', $saved);
        $this->assertStringNotContainsString('Solo Gerencia', $saved);
        $this->assertStringContainsString('id="contenido"', $saved, 'Only the panel goes.');
        $this->assertStringContainsString('id="mi-dia"', $saved);
    }

    public function test_management_without_published_figures_sees_no_panel(): void
    {
        $this->assertNoElement($this->home(as: $this->manager()), 'id', 'panel-gerencial');
    }

    /**
     * The set of docs/api.md §8.1, closed yesterday at 23:59.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function figures(array $attributes = []): void
    {
        $items = [
            ['key' => 'ventas', 'label' => 'Ventas del mes', 'value' => 1250000000, 'unit' => 'COP', 'goal' => 1500000000, 'note' => 'Sin IVA'],
            ['key' => 'cartera-vencida', 'label' => 'Cartera vencida', 'value' => 12.5, 'unit' => '%', 'goal' => null],
            ['key' => 'pedidos', 'label' => 'Pedidos', 'value' => 340, 'unit' => null, 'goal' => null],
            ['key' => 'pedidos-atrasados', 'label' => 'Pedidos atrasados', 'value' => 18, 'unit' => null, 'goal' => null],
        ];

        foreach ($items as $position => $item) {
            CompanyKpi::factory()->create([
                'company_id' => $this->company->id,
                'position' => $position + 1,
                'as_of' => CarbonImmutable::parse('2026-11-04 23:59', 'America/Bogota'),
                ...$item,
                ...$attributes,
            ]);
        }
    }

    /**
     * A «Gerencia» account of the company: the role and the stored
     * membership of the company's security group.
     */
    private function manager(): User
    {
        $user = User::factory()->microsoft($this->company)->create();
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $user->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user;
    }

    /**
     * @param  list<string>|null  $products
     */
    private function category(string $name, int $position, ?array $products = null): ProductCategory
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id, 'name' => $name, 'position' => $position]);

        foreach ($products ?? [] as $product) {
            $this->product($category, $product);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(ProductCategory $category, string $name, array $attributes = []): Product
    {
        return Product::factory()->published()->create([
            'company_id' => $this->company->id,
            'product_category_id' => $category->id,
            'name' => $name,
            ...$attributes,
        ]);
    }

    private function openingTag(string $markup): string
    {
        return substr($markup, 0, (int) strpos($markup, '>') + 1);
    }
}
