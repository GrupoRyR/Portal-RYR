<?php

namespace Tests\Feature\Catalog;

use App\Models\App;
use App\Models\AppCategory;
use Database\Seeders\AppCatalogSeeder;
use Database\Seeders\AppCategorySeeder;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Brief §1.1: slug => [url, companies, category, type, hosting, login].
     */
    private const CATALOG = [
        'toma-pedidos' => ['https://pedidos.segurex.com.co', ['segurex'], 'ventas', 'web', 'godaddy', 'microsoft'],
        'portafolio' => ['https://portafolio.derivadoscol.com.co/login.php', ['derivados'], 'ventas', 'web', 'godaddy', 'own'],
        'cuentas-por-pagar' => ['https://sgx-cuentas-por-pagar.vercel.app/login', ['segurex'], 'tesoreria', 'web', 'vercel', 'own'],
        'cartera' => ['https://sgx-visualizador-cartera.vercel.app/login', ['segurex'], 'contabilidad', 'web', 'vercel', 'own'],
        'camaras' => [null, ['segurex'], 'ti-o-sistemas', 'local', 'local', 'local'],
    ];

    private const ACCENTS = [
        'toma-pedidos' => '#4338CA',
        'portafolio' => '#6D28D9',
        'cuentas-por-pagar' => '#047857',
        'cartera' => '#B45309',
        'camaras' => '#334155',
    ];

    public function test_it_seeds_the_nine_categories_in_order(): void
    {
        $this->seed(AppCategorySeeder::class);

        $this->assertSame(
            ['Ventas', 'Contabilidad', 'Tesorería', 'TI o Sistemas', 'Gerencia', 'Producción', 'Diseño', 'Compras', 'Otros'],
            AppCategory::ordered()->pluck('name')->all(),
        );
    }

    public function test_it_seeds_the_catalog_of_the_brief(): void
    {
        $this->seedCatalog();

        $this->assertEqualsCanonicalizing(array_keys(self::CATALOG), App::pluck('slug')->all());

        foreach (self::CATALOG as $slug => [$url, $companies, $category, $type, $hosting, $login]) {
            $app = App::where('slug', $slug)->with(['category', 'companies'])->firstOrFail();

            $this->assertSame($url, $app->url, $slug);
            $this->assertSame($companies, $app->companies->pluck('slug')->all(), $slug);
            $this->assertSame($category, $app->category->slug, $slug);
            $this->assertSame($type, $app->type, $slug);
            $this->assertSame($hosting, $app->hosting, $slug);
            $this->assertSame($login, $app->login_type, $slug);
            $this->assertSame(App::STATUS_ACTIVE, $app->status, $slug);
            $this->assertSame(self::ACCENTS[$slug], $app->accent_color, $slug);
            $this->assertSame("icons/apps/{$slug}.svg", $app->icon_path, $slug);
            $this->assertSame("icons/apps/{$slug}-dark.svg", $app->icon_dark_path, $slug);
            $this->assertFileExists(public_path($app->icon_path));
            $this->assertFileExists(public_path($app->icon_dark_path));
            $this->assertNotEmpty($app->description, $slug);
            $this->assertNull($app->external_status, $slug);

            if ($type === App::TYPE_WEB) {
                $this->assertSame(App::OPEN_NEW, $app->open_in, $slug);
            }
        }
    }

    public function test_the_local_app_has_spanish_instructions_and_no_download(): void
    {
        $this->seedCatalog();

        $camaras = App::where('slug', 'camaras')->firstOrFail();

        $this->assertStringContainsString('menú Inicio', $camaras->local_instructions);
        $this->assertNull($camaras->download_url);
        $this->assertSame('Monitoreo de cámaras de seguridad', $camaras->description);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedCatalog();
        App::where('slug', 'cartera')->firstOrFail()->update(['name' => 'Cambiado']);

        $this->seedCatalog();

        $this->assertSame(9, AppCategory::count());
        $this->assertSame(5, App::count());
        $this->assertSame(5, \DB::table('app_companies')->count());
        $this->assertSame('Visualizador de cartera', App::where('slug', 'cartera')->value('name'));
    }

    public function test_the_database_seeder_includes_the_catalog(): void
    {
        $this->seed();

        $this->assertSame(9, AppCategory::count());
        $this->assertSame(5, App::count());
    }

    private function seedCatalog(): void
    {
        $this->seed([CompanySeeder::class, AppCategorySeeder::class, AppCatalogSeeder::class]);
    }
}
