<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `mis_apps`: the apps each account of the connection can open
 * (App\Catalog\AppCatalog), each once, labelled with the companies of the
 * accounts that can open it.
 */
class MisAppsTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    /**
     * An app linked to the companies, granted to everyone in each of them.
     */
    private function appFor(string $name, Company ...$companies): App
    {
        $app = App::factory()->forCompanies(...$companies)->create([
            'name' => $name,
            'description' => "Descripción de {$name}",
            'app_category_id' => AppCategory::factory()->create(['name' => 'Operación']),
        ]);

        foreach ($companies as $company) {
            AppAccessRule::forCompany($app, $company);
        }

        return $app;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function apps(): array
    {
        return $this->toolResult('mis_apps')['apps'];
    }

    public function test_both_accounts_apps_come_once_and_labelled(): void
    {
        $shared = $this->appFor('Nómina', $this->segurex, $this->derivados);
        $segurex = $this->appFor('Cámaras', $this->segurex);
        $derivados = $this->appFor('Inventario', $this->derivados);

        $apps = collect($this->apps())->keyBy('nombre');

        // The approving account's apps in catalog order, then the others'.
        $this->assertSame(['Cámaras', 'Nómina', 'Inventario'], $apps->keys()->all());
        $this->assertSame([
            'nombre' => 'Nómina',
            'descripcion' => 'Descripción de Nómina',
            'categoria' => 'Operación',
            'url' => route('apps.open', $shared),
            'en_mantenimiento' => false,
            'empresas' => ['Segurex', 'Derivados'],
            'nota' => 'El enlace abre la app con la cuenta del portal que tenga la sesión en el navegador. Para esta app, usa tu cuenta de Segurex (jhon.castro@segurex.com) o de Derivados (derivados.info@derivados.com).',
        ], $apps['Nómina']);
        $this->assertSame(['Segurex'], $apps['Cámaras']['empresas']);
        $this->assertSame('El enlace abre la app con la cuenta del portal que tenga la sesión en el navegador. Para esta app, usa tu cuenta de Segurex (jhon.castro@segurex.com).', $apps['Cámaras']['nota']);
        $this->assertSame(route('apps.open', $segurex), $apps['Cámaras']['url']);
        $this->assertSame(['Derivados'], $apps['Inventario']['empresas']);
        $this->assertSame(route('apps.open', $derivados), $apps['Inventario']['url']);
    }

    public function test_an_app_in_maintenance_says_so_and_an_empty_category_is_null(): void
    {
        $app = App::factory()->maintenance()->forCompanies($this->segurex)->create([
            'name' => 'Cámaras',
            'app_category_id' => AppCategory::factory()->create(['name' => '']),
        ]);
        AppAccessRule::forCompany($app, $this->segurex);

        $apps = $this->apps();

        $this->assertTrue($apps[0]['en_mantenimiento']);
        $this->assertNull($apps[0]['categoria']);
    }

    public function test_apps_of_a_company_outside_the_connection_or_hidden_or_not_granted_never_appear(): void
    {
        $this->appFor('Solo Otra', $this->other);
        $hidden = App::factory()->hidden()->forCompanies($this->segurex)->create(['name' => 'Oculta']);
        AppAccessRule::forCompany($hidden, $this->segurex);
        // Linked to Segurex, but granted to nobody there.
        App::factory()->forCompanies($this->segurex)->create(['name' => 'Sin permiso']);
        // Linked to Segurex and Otra: listed for Segurex only.
        $shared = App::factory()->forCompanies($this->segurex, $this->other)->create(['name' => 'Compartida']);
        AppAccessRule::forCompany($shared, $this->segurex);
        AppAccessRule::forCompany($shared, $this->other);

        $apps = $this->apps();

        $this->assertSame(['Compartida'], array_column($apps, 'nombre'));
        $this->assertSame(['Segurex'], $apps[0]['empresas']);
    }

    public function test_after_unlinking_the_other_company_s_apps_no_longer_appear(): void
    {
        $this->appFor('Inventario', $this->derivados);
        $this->assertCount(1, $this->apps());

        $this->unlinkTheLinkedAccount();

        $this->assertSame([], $this->apps());
    }

    #[DataProvider('linkedAccountThatCanNoLongerConnect')]
    public function test_a_linked_account_that_can_no_longer_connect_is_not_read_for(string $case): void
    {
        $this->appFor('Inventario', $this->derivados);

        $this->makeLinkedAccountUnusable($case);

        $this->assertSame([], $this->apps());
    }

    public function test_at_most_one_hundred(): void
    {
        $category = AppCategory::factory()->create();
        App::factory()->count(105)->forCompanies($this->segurex)->create(['app_category_id' => $category->id])
            ->each(fn (App $app) => AppAccessRule::forCompany($app, $this->segurex));

        $result = $this->toolResult('mis_apps');

        $this->assertSame(100, $result['limite']);
        $this->assertCount(100, $result['apps']);
    }
}
