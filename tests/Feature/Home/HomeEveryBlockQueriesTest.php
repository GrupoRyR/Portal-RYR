<?php

namespace Tests\Feature\Home;

use App\Auth\MicrosoftLogin;
use App\Models\App;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\Integration;
use App\Models\ItService;
use App\Models\MarketRate;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * The home with every block on the page (odd/tasks/inicio-bloques.md H4):
 * markets, the management panel, news, the catalog, tasks with
 * «Completar», and «Estado de TI» run a fixed number of queries, however
 * many rows each block has.
 */
class HomeEveryBlockQueriesTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private const TENANT = '6f1c2a9e-0000-4000-8000-000000000001';

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private Company $company;

    private User $user;

    private Integration $integration;

    private ProductCategory $category;

    private NewsSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->forTenant(self::TENANT)->withManagementGroup(self::GROUP)->create();
        $this->user = User::factory()->microsoft($this->company)->create();
        $this->user->assignRole(MicrosoftLogin::MANAGEMENT);
        $this->user->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        $tareas = App::factory()->create(['name' => 'Tareas', 'url' => 'https://tareas.segurex.com.co']);
        $this->integration = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $tareas->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
        $this->integration->issueSigningSecret();
        $this->category = ProductCategory::factory()->create(['company_id' => $this->company->id]);
        $this->source = NewsSource::factory()->create(['company_id' => $this->company->id]);

        MarketRate::factory()->trm()->create(['valid_from' => '2026-11-03']);
        MarketRate::factory()->eur()->create(['valid_from' => '2026-11-03']);
    }

    public function test_every_block_together_runs_a_fixed_number_of_queries(): void
    {
        $this->rows(1);
        $this->countQueries(); // Warms the settings and permission caches, as on any later request.
        $one = $this->countQueries();

        $this->rows(6);
        $many = $this->countQueries();

        $response = $this->home();
        foreach (['data-markets', 'id="panel-gerencial"', 'id="noticias"', 'id="catalogos"', 'id="tareas"', 'id="estado-ti"', 'data-complete-form'] as $marker) {
            $this->assertStringContainsString($marker, $response->getContent(), $marker);
        }

        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(HomeQueryBudget::WITH_EVERY_BLOCK, $many);
    }

    private function rows(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $app = $this->openableApp();
            $app->forceFill(['status' => App::STATUS_MAINTENANCE])->save();
            ItService::factory()->down()->create(['company_id' => $this->company->id]);
            NewsItem::factory()->create(['news_source_id' => $this->source->id]);
            Product::factory()->published()->create(['company_id' => $this->company->id, 'product_category_id' => ProductCategory::factory()->create(['company_id' => $this->company->id])->id]);
            CompanyKpi::factory()->create(['company_id' => $this->company->id, 'position' => CompanyKpi::query()->count() + 1]);
            // Completable tasks of the signed integration; approvals each from an integration of its own.
            WorkItem::factory()->create([
                'integration_id' => $this->integration->id,
                'kind' => WorkItem::KIND_TASK,
                'assignee_tenant_id' => self::TENANT,
                'assignee_oid' => strtolower((string) $this->user->azure_oid),
            ]);
            WorkItem::factory()->create([
                'integration_id' => Integration::factory()->create([
                    'abilities' => [Integration::WORK_WRITE],
                    'app_id' => App::factory()->create(['url' => "https://aprobaciones{$i}.segurex.com.co"])->id,
                ])->id,
                'kind' => WorkItem::KIND_APPROVAL,
                'assignee_tenant_id' => self::TENANT,
                'assignee_oid' => strtolower((string) $this->user->azure_oid),
            ]);
        }
    }

    private function countQueries(): int
    {
        $user = $this->user->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }
}
