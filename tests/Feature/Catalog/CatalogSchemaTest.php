<?php

namespace Tests\Feature\Catalog;

use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalog_tables_have_their_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('app_categories', ['id', 'name', 'slug', 'sort_order', 'created_at', 'updated_at']));

        $this->assertTrue(Schema::hasColumns('apps', [
            'id', 'slug', 'name', 'description', 'icon_path', 'icon_dark_path', 'accent_color',
            'url', 'type', 'open_in', 'hosting', 'login_type', 'app_category_id', 'sort_order',
            'status', 'maintenance_message', 'maintenance_until', 'local_instructions', 'download_url',
            'external_status', 'external_status_message', 'external_status_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('app_companies', ['app_id', 'company_id']));
    }

    public function test_category_and_app_slugs_are_unique(): void
    {
        AppCategory::factory()->create(['slug' => 'ventas']);
        App::factory()->create(['slug' => 'cartera']);

        $this->assertUniqueViolation(fn () => AppCategory::factory()->create(['slug' => 'ventas']));
        $this->assertUniqueViolation(fn () => App::factory()->create(['slug' => 'cartera']));
    }

    public function test_an_app_is_linked_to_a_company_only_once(): void
    {
        $app = App::factory()->create();
        $company = Company::factory()->create();
        $app->companies()->attach($company);

        $this->assertUniqueViolation(fn () => $app->companies()->attach($company));
    }

    public function test_relations_between_apps_categories_and_companies(): void
    {
        $category = AppCategory::factory()->create();
        $app = App::factory()->for($category, 'category')->create();
        $company = Company::factory()->create();
        $app->companies()->attach($company);

        $this->assertTrue($app->category->is($category));
        $this->assertTrue($category->apps->first()->is($app));
        $this->assertTrue($company->apps->first()->is($app));
        $this->assertTrue($app->companies->first()->is($company));
    }

    private function assertUniqueViolation(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a unique constraint violation.');
    }
}
