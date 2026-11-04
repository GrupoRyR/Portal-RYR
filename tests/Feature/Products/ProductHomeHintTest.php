<?php

namespace Tests\Feature\Products;

use App\Livewire\Admin\ProductManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Jhon's lock that did not show on the home page (odd/tasks/observaciones.md
 * O4): a product is created hidden by design, and the home page only shows
 * the published products of an active company to its own people. Admin ›
 * Catálogos now says, next to each product, why it is not on the home page.
 */
class ProductHomeHintTest extends TestCase
{
    use RefreshDatabase;

    private const HIDDEN = 'No se muestra en el inicio: está oculto. Pulsa «Publicar» para mostrarlo.';

    private const INACTIVE = 'No se muestra en el inicio: la empresa está inactiva.';

    private User $admin;

    private Company $segurex;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex LATAM SAS', 'short_name' => 'Segurex']);
        $this->category = ProductCategory::factory()->create(['company_id' => $this->segurex->id, 'name' => 'Cerraduras']);
        $this->admin = tap(User::factory()->create())->assignRole('portal_admin');
    }

    public function test_a_new_product_says_it_is_hidden_until_it_is_published(): void
    {
        $component = Livewire::actingAs($this->admin)->test(ProductManager::class)
            ->set('companyId', $this->segurex->id)
            ->call('newProduct')
            ->set('productName', 'Cerradura digital')
            ->set('categoryId', $this->category->id)
            ->call('saveProduct')
            ->assertHasNoErrors()
            ->assertSeeText('Se creó «Cerradura digital». Está oculto hasta que lo publiques.')
            ->assertSeeText(self::HIDDEN);

        $product = Product::query()->sole();

        $component->call('togglePublished', $product->id)
            ->assertSeeText('«Cerradura digital» ya se muestra en el catálogo.')
            ->assertDontSeeText('No se muestra en el inicio');
    }

    public function test_a_published_product_of_an_inactive_company_says_why_it_is_not_shown(): void
    {
        $this->segurex->update(['is_active' => false]);
        Product::factory()->published()->create(['company_id' => $this->segurex->id, 'product_category_id' => $this->category->id, 'name' => 'Cerradura digital']);

        Livewire::actingAs($this->admin)->test(ProductManager::class)
            ->set('companyId', $this->segurex->id)
            ->assertSeeText(self::INACTIVE)
            ->assertDontSeeText(self::HIDDEN);
    }

    public function test_the_list_says_who_sees_the_catalog(): void
    {
        Livewire::actingAs($this->admin)->test(ProductManager::class)
            ->set('companyId', $this->segurex->id)
            ->assertSeeText('En el inicio, cada persona ve solo los productos publicados de la empresa con la que entró.');
    }
}
