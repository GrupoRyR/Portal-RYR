<?php

namespace Tests\Feature\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Products\ProductBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $sdc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create();
        $this->sdc = Company::factory()->create();
    }

    public function test_it_returns_the_categories_of_one_company_in_order_with_their_published_products(): void
    {
        $residencial = $this->category($this->segurex, 'Residencial', 2);
        $smart = $this->category($this->segurex, 'Línea smart', 1);
        $this->product($residencial, 'Alarma', 2, true);
        $this->product($residencial, 'Sirena', 1, true);
        $this->product($smart, 'Cámara', 1, true);

        $board = (new ProductBoard)->forCompany($this->segurex);

        $this->assertSame(['Línea smart', 'Residencial'], $board->pluck('name')->all());
        $this->assertSame(['Sirena', 'Alarma'], $board[1]->products->pluck('name')->all());
    }

    public function test_unpublished_products_are_hidden_and_categories_left_empty_are_left_out(): void
    {
        $visible = $this->category($this->segurex, 'Comercial', 1);
        $onlyDrafts = $this->category($this->segurex, 'Control de acceso', 2);
        $this->product($visible, 'Publicado', 1, true);
        $this->product($visible, 'Borrador', 2, false);
        $this->product($onlyDrafts, 'Otro borrador', 1, false);

        $board = (new ProductBoard)->forCompany($this->segurex);

        $this->assertSame(['Comercial'], $board->pluck('name')->all());
        $this->assertSame(['Publicado'], $board[0]->products->pluck('name')->all());
    }

    public function test_another_company_never_leaks_into_the_board(): void
    {
        $mine = $this->category($this->segurex, 'Residencial', 1);
        $theirs = $this->category($this->sdc, 'Hipoclorito', 1);
        $this->product($mine, 'Alarma', 1, true);
        $this->product($theirs, 'Cloro', 1, true);

        $segurex = (new ProductBoard)->forCompany($this->segurex);
        $sdc = (new ProductBoard)->forCompany($this->sdc);

        $this->assertSame(['Alarma'], $segurex->flatMap->products->pluck('name')->all());
        $this->assertSame(['Cloro'], $sdc->flatMap->products->pluck('name')->all());
    }

    public function test_a_product_filed_under_another_companys_category_is_not_shown(): void
    {
        $theirs = $this->category($this->sdc, 'Hipoclorito', 1);
        Product::factory()->published()->create([
            'company_id' => $this->segurex->id,
            'product_category_id' => $theirs->id,
            'name' => 'Mal archivado',
        ]);

        $this->assertTrue((new ProductBoard)->forCompany($this->segurex)->isEmpty());
        $this->assertTrue((new ProductBoard)->forCompany($this->sdc)->isEmpty());
    }

    public function test_it_reads_with_two_queries_whatever_the_size_of_the_catalog(): void
    {
        foreach (range(1, 4) as $n) {
            $category = $this->category($this->segurex, "Categoría {$n}", $n);
            foreach (range(1, 5) as $m) {
                $this->product($category, "Producto {$n}.{$m}", $m, true);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $board = (new ProductBoard)->forCompany($this->segurex);
        $board->each(fn (ProductCategory $category) => $category->products->each(fn (Product $product) => $product->photoUrl()));

        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(4, $board);
        $this->assertSame(20, $board->sum(fn (ProductCategory $category) => $category->products->count()));
    }

    public function test_a_company_without_a_catalog_gets_an_empty_board_with_at_most_two_queries(): void
    {
        DB::enableQueryLog();

        $this->assertTrue((new ProductBoard)->forCompany($this->sdc)->isEmpty());
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
    }

    private function category(Company $company, string $name, int $position): ProductCategory
    {
        return ProductCategory::factory()->for($company)->create(['name' => $name, 'position' => $position]);
    }

    private function product(ProductCategory $category, string $name, int $position, bool $published): Product
    {
        return Product::factory()->create([
            'company_id' => $category->company_id,
            'product_category_id' => $category->id,
            'name' => $name,
            'position' => $position,
            'published' => $published,
        ]);
    }
}
