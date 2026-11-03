<?php

namespace Tests\Feature\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_name_is_unique_within_a_company_but_not_across_companies(): void
    {
        $segurex = Company::factory()->create();
        $sdc = Company::factory()->create();

        ProductCategory::factory()->for($segurex)->create(['name' => 'Residencial']);
        ProductCategory::factory()->for($sdc)->create(['name' => 'Residencial']);

        $this->expectException(QueryException::class);

        ProductCategory::factory()->for($segurex)->create(['name' => 'Residencial']);
    }

    public function test_a_product_belongs_to_a_company_and_a_category_of_that_company(): void
    {
        $company = Company::factory()->create();
        $category = ProductCategory::factory()->for($company)->create();
        $product = Product::factory()->for($company)->for($category, 'category')->create();

        $this->assertTrue($product->company->is($company));
        $this->assertTrue($product->category->is($category));
        $this->assertTrue($category->products->first()->is($product));
    }

    public function test_deleting_a_category_deletes_its_product_rows(): void
    {
        $category = ProductCategory::factory()->create();
        Product::factory()->for($category->company)->for($category, 'category')->count(2)->create();

        $category->delete();

        $this->assertSame(0, Product::count());
    }

    public function test_the_published_flag_is_a_boolean_and_defaults_to_hidden(): void
    {
        $product = Product::factory()->create();

        $this->assertFalse($product->fresh()->published);
        $this->assertTrue(Product::factory()->published()->create()->fresh()->published);
    }

    public function test_every_index_and_foreign_key_name_fits_the_mysql_limit(): void
    {
        foreach (['product_categories', 'products'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "Index {$index['name']} is too long.");
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $this->assertLessThanOrEqual(64, strlen((string) $foreignKey['name']), "Foreign key {$foreignKey['name']} is too long.");
            }
        }

        $this->assertGreaterThan(0, count(Schema::getIndexes('products')));
    }

    public function test_the_policies_allow_only_super_and_portal_admins(): void
    {
        $this->seed(RoleSeeder::class);

        $super = tap(User::factory()->create())->assignRole('super_admin');
        $admin = tap(User::factory()->create())->assignRole('portal_admin');
        $editor = tap(User::factory()->create())->assignRole('announcements_editor');
        $plain = User::factory()->create();
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();

        foreach ([$super, $admin] as $allowed) {
            foreach (['viewAny', 'create'] as $ability) {
                $this->assertTrue(Gate::forUser($allowed)->allows($ability, Product::class));
                $this->assertTrue(Gate::forUser($allowed)->allows($ability, ProductCategory::class));
            }
            foreach (['update', 'delete'] as $ability) {
                $this->assertTrue(Gate::forUser($allowed)->allows($ability, $product));
                $this->assertTrue(Gate::forUser($allowed)->allows($ability, $category));
            }
        }

        foreach ([$editor, $plain] as $denied) {
            $this->assertFalse(Gate::forUser($denied)->allows('viewAny', Product::class));
            $this->assertFalse(Gate::forUser($denied)->allows('create', ProductCategory::class));
            $this->assertFalse(Gate::forUser($denied)->allows('update', $product));
            $this->assertFalse(Gate::forUser($denied)->allows('delete', $category));
        }
    }
}
