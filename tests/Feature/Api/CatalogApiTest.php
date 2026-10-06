<?php

namespace Tests\Feature\Api;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private AppCategory $tesoreria;

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.allowed_app_hosts' => ['segurex.com.co', 'sgx-*.vercel.app']]);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam S.A.S.', 'short_name' => 'Segurex', 'slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Derivados S.A.S.', 'short_name' => 'Derivados', 'slug' => 'derivados']);
        $this->tesoreria = AppCategory::factory()->create(['name' => 'Tesorería', 'slug' => 'tesoreria']);
    }

    private function token(array $abilities = [Integration::CATALOG_READ]): string
    {
        return Integration::factory()->create(['abilities' => $abilities])->issueToken();
    }

    private function catalog(array $query = [], ?string $token = null): TestResponse
    {
        $token ??= $this->token();
        $this->app['auth']->forgetGuards();

        return $this->get('/api/v1/apps'.($query === [] ? '' : '?'.http_build_query($query)), ['Authorization' => "Bearer {$token}"]);
    }

    private function app(array $attributes = []): App
    {
        return App::factory()->forCompanies($this->segurex)->create([
            'url' => 'https://cartera.segurex.com.co',
            'app_category_id' => $this->tesoreria->id,
            ...$attributes,
        ]);
    }

    public function test_the_catalog_lists_each_app_without_users_or_access_rules(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(9, 30));
        $app = $this->app([
            'slug' => 'cartera',
            'name' => 'Visualizador de cartera',
            'hosting' => 'vercel',
            'login_type' => 'microsoft',
        ]);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->catalog()
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['data' => [[
                'slug' => 'cartera',
                'name' => 'Visualizador de cartera',
                'type' => 'web',
                'url' => 'https://cartera.segurex.com.co',
                'category' => ['slug' => 'tesoreria', 'name' => 'Tesorería'],
                'all_companies' => false,
                'companies' => [['slug' => 'segurex', 'name' => 'Segurex']],
                'hosting' => 'vercel',
                'login_type' => 'microsoft',
                'status' => [
                    'current' => 'active',
                    'maintenance' => null,
                    'reported' => null,
                ],
                'updated_at' => '2026-10-06T09:30:00-05:00',
            ]]])
            ->assertDontSee('APP-Cartera');
    }

    public function test_apps_for_every_company_list_the_active_companies(): void
    {
        Company::factory()->create(['short_name' => 'Inactiva', 'is_active' => false]);
        $this->app(['slug' => 'camaras', 'available_to_all_companies' => true]);

        $this->catalog()
            ->assertJsonPath('data.0.all_companies', true)
            ->assertJsonPath('data.0.companies', [
                ['slug' => 'derivados', 'name' => 'Derivados'],
                ['slug' => 'segurex', 'name' => 'Segurex'],
            ]);
    }

    public function test_maintenance_and_local_apps_are_described(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(9, 30));
        $this->app(['slug' => 'nomina', 'status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Cierre de mes', 'maintenance_until' => now()->addHours(2)]);
        App::factory()->local()->forCompanies($this->segurex)->create(['slug' => 'camaras', 'sort_order' => 5]);

        $this->catalog()
            ->assertJsonPath('data.0.slug', 'nomina')
            ->assertJsonPath('data.0.status.current', 'maintenance')
            ->assertJsonPath('data.0.status.maintenance', ['message' => 'Cierre de mes', 'until' => '2026-10-06T11:30:00-05:00'])
            ->assertJsonPath('data.1.slug', 'camaras')
            ->assertJsonPath('data.1.type', 'local')
            ->assertJsonPath('data.1.url', null);
    }

    public function test_hidden_apps_are_left_out(): void
    {
        $this->app(['slug' => 'visible']);
        $this->app(['slug' => 'oculta', 'status' => App::STATUS_HIDDEN]);

        $this->assertSame(['visible'], array_column($this->catalog()->json('data'), 'slug'));
    }

    public function test_updated_since_returns_only_apps_changed_from_that_moment(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(8, 0));
        $this->app(['slug' => 'vieja']);
        $this->travelTo(now()->setTime(10, 0));
        $this->app(['slug' => 'nueva']);

        // 14:30 UTC is 09:30 in Bogotá: only the 10:00 app changed since.
        $this->assertSame(['nueva'], array_column($this->catalog(['updated_since' => '2026-10-06T14:30:00Z'])->json('data'), 'slug'));
        $this->assertEqualsCanonicalizing(['vieja', 'nueva'], array_column($this->catalog(['updated_since' => '2026-10-06T08:00:00-05:00'])->json('data'), 'slug'));
    }

    public function test_an_invalid_updated_since_is_a_json_422(): void
    {
        $this->catalog(['updated_since' => 'ayer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('updated_since');
    }

    public function test_a_token_without_catalog_read_gets_403(): void
    {
        $this->catalog([], $this->token([Integration::STATUS_WRITE]))
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_the_catalog_needs_a_token(): void
    {
        $this->get('/api/v1/apps')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_changing_the_companies_of_an_app_marks_it_as_changed(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(8, 0));
        $app = $this->app();
        $this->travelTo(now()->setTime(11, 0));

        $app->syncCompanies([$this->segurex->id, $this->derivados->id]);

        $this->assertSame('11:00', $app->fresh()->updated_at->format('H:i'));
    }
}
