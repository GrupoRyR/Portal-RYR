<?php

namespace Tests\Feature\Home;

use App\Auth\MicrosoftLogin;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\Feature\Management\Concerns\FakesBasesSap;
use Tests\TestCase;

/**
 * The «Panel gerencial» of the home on «Bases de datos SAP»
 * (odd/tasks/panel-gerencial-sap.md, T2): the month's figures of the
 * account's own company when the connections are configured, the
 * published figures otherwise, and a plain message when SAP cannot be read.
 */
class HomeSapPanelTest extends TestCase
{
    use FakesBasesSap;
    use ReadsTheHome;
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private const OTHER_GROUP = 'bbbbbbbb-0000-4000-8000-000000000002';

    private Company $company;

    private Company $derivados;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'America/Bogota'));
        $this->company = Company::factory()->withManagementGroup(self::GROUP)->create(['slug' => 'segurex', 'name' => 'Segurex Latam']);
        $this->derivados = Company::factory()->withManagementGroup(self::OTHER_GROUP)->create(['slug' => 'derivados', 'name' => 'Soluciones y Derivados']);
        $this->user = User::factory()->microsoft($this->company)->create();
        $this->openableApp();
        $this->openableApp(company: $this->derivados);
        $this->fakeBasesSap();
    }

    /**
     * A «Gerencia» account of the company: the role and the stored
     * membership of the company's security group.
     */
    private function manager(?Company $company = null, string $group = self::GROUP): User
    {
        $user = User::factory()->microsoft($company ?? $this->company)->create();
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $user->syncGroups([['id' => $group, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user;
    }

    /** The month of Segurex: 1.250.000.000 sold, 312.500.000 of gross profit (25 %). */
    private function segurexMonth(): void
    {
        $this->sapSale(['slp_code' => 7, 'vendedor' => 'Ana Ruiz', 'total' => 1000000000, 'ganancia_bruta' => 250000000]);
        $this->sapSale(['slp_code' => 9, 'vendedor' => 'Luis Gómez', 'total' => 300000000, 'ganancia_bruta' => 75000000]);
        $this->sapSale(['slp_code' => -1, 'vendedor' => null, 'tipo_objeto' => 14, 'clase_documento' => 'nota_credito', 'total' => -50000000, 'ganancia_bruta' => -12500000]);
        $this->sapReceivable(['sin_vencer' => 400000000]);
        $this->sapReceivable(['sin_vencer' => 0, 'd_0_30' => 80000000, 'd_mas_180' => 20000000]);
    }

    private function text(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    // --- Who sees it -----------------------------------------------------------

    public function test_only_the_companys_management_sees_the_sap_figures(): void
    {
        $this->segurexMonth();

        $this->assertNoElement($this->home(), 'id', 'panel-gerencial');

        $admin = User::factory()->microsoft($this->company)->create();
        $admin->assignRole('portal_admin');
        $this->assertNoElement($this->home(as: $admin), 'id', 'panel-gerencial');

        $roleOnly = User::factory()->microsoft($this->company)->create();
        $roleOnly->assignRole(MicrosoftLogin::MANAGEMENT);
        $this->assertNoElement($this->home(as: $roleOnly), 'id', 'panel-gerencial');

        // The group of another company does not open this one.
        $this->assertNoElement($this->home(as: $this->manager(group: self::OTHER_GROUP)), 'id', 'panel-gerencial');

        $panel = $this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial');
        $this->assertStringContainsString('$1.250.000.000', $panel);
    }

    public function test_each_company_reads_its_own_code(): void
    {
        $this->segurexMonth();
        $this->sapSale(['empresa' => 'SDC', 'slp_code' => 3, 'vendedor' => 'Marta Díaz', 'total' => 40000000, 'ganancia_bruta' => 10000000]);
        $this->sapReceivable(['empresa' => 'SDC', 'sin_vencer' => 7000000]);

        $segurex = $this->text($this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial'));
        $this->assertStringContainsString('$1.250.000.000', $segurex);
        $this->assertStringContainsString('Ana Ruiz', $segurex);
        $this->assertStringNotContainsString('$40.000.000', $segurex);
        $this->assertStringNotContainsString('Marta Díaz', $segurex);

        $derivados = $this->text($this->element($this->home(as: $this->manager($this->derivados, self::OTHER_GROUP)), 'id', 'panel-gerencial'));
        $this->assertStringContainsString('$40.000.000', $derivados);
        $this->assertStringContainsString('$7.000.000', $derivados);
        $this->assertStringContainsString('Marta Díaz', $derivados);
        $this->assertStringNotContainsString('$1.250.000.000', $derivados);
        $this->assertStringNotContainsString('Ana Ruiz', $derivados);
    }

    // --- What it shows ---------------------------------------------------------

    public function test_the_panel_shows_sales_margin_receivables_and_sellers(): void
    {
        $this->segurexMonth();

        $response = $this->home(as: $this->manager());
        $panel = $this->element($response, 'id', 'panel-gerencial');
        $text = $this->text($panel);

        $this->assertStringContainsString('Panel gerencial', $text);
        $this->assertStringContainsString('Solo Gerencia', $text);
        $this->assertStringContainsString('Noviembre de 2026', $text);
        $this->assertStringContainsString('Venta neta del mes', $text);
        $this->assertStringContainsString('$1.250.000.000', $text);
        $this->assertStringContainsString('Ganancia bruta', $text);
        $this->assertStringContainsString('$312.500.000', $text);
        $this->assertStringContainsString('Margen 25 %', $text);
        $this->assertStringContainsString('Cartera total', $text);
        $this->assertStringContainsString('$500.000.000', $text);
        $this->assertStringContainsString('Fuente: Bases de datos SAP', $text);
        $this->assertContains('#panel-gerencial', $this->sectionLinks($response));
        $this->assertStringNotContainsString('No pudimos leer', $text);
    }

    public function test_the_coverage_notice_is_always_shown(): void
    {
        $this->segurexMonth();

        $this->assertStringContainsString(
            'Margen calculado sobre el 100 % de la venta neta',
            $this->text($this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial')),
        );
    }

    public function test_the_coverage_notice_says_which_share_has_gross_profit(): void
    {
        $this->sapSale(['total' => 600000, 'ganancia_bruta' => 120000]);
        $this->sapSale(['total' => 400000, 'ganancia_bruta' => null]);

        $text = $this->text($this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial'));

        $this->assertStringContainsString('Margen 20 %', $text);
        $this->assertStringContainsString('Margen calculado sobre el 60 % de la venta neta', $text);
    }

    public function test_the_ranges_are_a_table_with_their_values_in_text(): void
    {
        $this->segurexMonth();

        $response = $this->home(as: $this->manager());
        $rows = $this->xpath($response)->query('//*[@id="panel-gerencial"]//table//tbody/tr');

        $this->assertSame(8, $rows->length);
        $cells = array_map(fn ($row) => $this->text($row->textContent), iterator_to_array($rows));
        $this->assertStringContainsString('Sin vencer', $cells[0]);
        $this->assertStringContainsString('$400.000.000', $cells[0]);
        $this->assertStringContainsString('80 %', $cells[0]);
        $this->assertStringContainsString('0 a 30 días', $cells[1]);
        $this->assertStringContainsString('$80.000.000', $cells[1]);
        $this->assertStringContainsString('16 %', $cells[1]);
        $this->assertStringContainsString('31 a 60 días', $cells[2]);
        $this->assertStringContainsString('$0', $cells[2]);
        $this->assertStringContainsString('Más de 180 días', $cells[7]);
        $this->assertStringContainsString('$20.000.000', $cells[7]);
        $this->assertStringContainsString('4 %', $cells[7]);
        $this->assertStringNotContainsString('no suman', $this->text($this->element($response, 'id', 'panel-gerencial')));
    }

    public function test_a_credit_balance_range_has_no_share_and_the_others_share_the_positive_sum(): void
    {
        $this->sapReceivable(['sin_vencer' => 150000, 'd_0_30' => -50000]);
        $this->sapReceivable(['sin_vencer' => 50000]);

        $rows = $this->xpath($this->home(as: $this->manager()))->query('//*[@id="panel-gerencial"]//table//tbody/tr');
        $cells = array_map(fn ($row) => $this->text($row->textContent), iterator_to_array($rows));

        // Over the positive ranges (200.000), never over the balance (150.000): no share passes 100 %.
        $this->assertStringContainsString('$200.000', $cells[0]);
        $this->assertStringContainsString('100 %', $cells[0]);
        $this->assertStringContainsString('-$50.000', $cells[1]);
        $this->assertStringNotContainsString('%', $cells[1]);
        $this->assertSame(0, $this->xpath($this->home(as: $this->manager()))->query('//*[@id="panel-gerencial"]//tbody/tr[2]//*[contains(@class, "g-bar")]')->length);
        $this->assertStringContainsString('0 %', $cells[2]);
    }

    public function test_ranges_that_do_not_add_up_are_pointed_out(): void
    {
        $this->sapReceivable(['sin_vencer' => 100000, 'saldo' => 150000]);

        $text = $this->text($this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial'));

        $this->assertStringContainsString('Los rangos no suman la cartera total: faltan $50.000.', $text);
    }

    public function test_the_top_sellers_are_listed_in_order(): void
    {
        $this->segurexMonth();

        $items = $this->xpath($this->home(as: $this->manager()))->query('//*[@id="panel-gerencial"]//ol/li');

        $this->assertSame(3, $items->length);
        $this->assertStringContainsString('Ana Ruiz', $items->item(0)->textContent);
        $this->assertStringContainsString('$1.000.000.000', $items->item(0)->textContent);
        $this->assertStringContainsString('Luis Gómez', $items->item(1)->textContent);
        $this->assertStringContainsString('Sin vendedor', $items->item(2)->textContent);
        $this->assertStringContainsString('-$50.000.000', $items->item(2)->textContent);
    }

    public function test_names_from_sap_are_escaped(): void
    {
        $this->sapSale(['vendedor' => '<script>alert(1)</script>']);

        $html = $this->home(as: $this->manager())->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // --- Fallback and failure ----------------------------------------------------

    public function test_without_configuration_the_published_figures_stay(): void
    {
        $this->segurexMonth();
        config(['database.connections.sap_ventas.username' => null]);
        CompanyKpi::factory()->create(['company_id' => $this->company->id, 'label' => 'Ventas publicadas']);

        $text = $this->text($this->element($this->home(as: $this->manager()), 'id', 'panel-gerencial'));

        $this->assertStringContainsString('Ventas publicadas', $text);
        $this->assertStringNotContainsString('Venta neta del mes', $text);
        $this->assertStringNotContainsString('$1.250.000.000', $text);
    }

    public function test_without_configuration_or_published_figures_there_is_no_panel(): void
    {
        config(['database.connections.sap_cartera.host' => '']);

        $this->assertNoElement($this->home(as: $this->manager()), 'id', 'panel-gerencial');
    }

    public function test_a_company_without_a_sap_code_keeps_the_published_figures(): void
    {
        $other = Company::factory()->withManagementGroup(self::OTHER_GROUP)->create(['slug' => 'otra']);
        $this->openableApp(company: $other);
        CompanyKpi::factory()->create(['company_id' => $other->id, 'label' => 'Ventas publicadas']);

        $text = $this->text($this->element($this->home(as: $this->manager($other, self::OTHER_GROUP)), 'id', 'panel-gerencial'));

        $this->assertStringContainsString('Ventas publicadas', $text);
    }

    public function test_when_sap_cannot_be_read_the_panel_says_so_and_the_page_works(): void
    {
        Exceptions::fake();
        $this->fakeBasesSap(withViews: false);
        CompanyKpi::factory()->create(['company_id' => $this->company->id, 'label' => 'Ventas publicadas']);

        $response = $this->home(as: $this->manager());
        $text = $this->text($this->element($response, 'id', 'panel-gerencial'));

        $this->assertStringContainsString('No pudimos leer las cifras de SAP', $text);
        $this->assertStringNotContainsString('Venta neta del mes', $text);
        $this->assertStringNotContainsString('Ventas publicadas', $text);
        $this->assertStringNotContainsString('no such table', $response->getContent());
        Exceptions::assertReported(QueryException::class);
        $this->element($response, 'id', 'mis-aplicaciones');
    }

    // --- Queries ---------------------------------------------------------------

    public function test_cached_figures_cost_no_queries(): void
    {
        $this->segurexMonth();
        $manager = $this->manager();

        $this->countQueries($manager); // Reads the views once, and warms the other caches.
        $queries = $this->countQueries($manager);

        $this->assertSame([], array_filter($queries, fn (string $connection) => str_starts_with($connection, 'sap_')));
        $this->assertLessThanOrEqual(HomeQueryBudget::WITH_EVERY_BLOCK, count($queries));

        // No more than the published figures cost on the same page.
        CompanyKpi::factory()->create(['company_id' => $this->company->id]);
        config(['database.connections.sap_ventas.username' => null]);
        $this->countQueries($manager);
        $this->assertLessThanOrEqual(count($this->countQueries($manager)), count($queries));
    }

    public function test_an_ordinary_account_never_reads_sap(): void
    {
        $this->segurexMonth();

        // The same page with the connections configured and without them.
        $this->countQueries($this->user);
        $configured = $this->countQueries($this->user);
        config(['database.connections.sap_ventas.username' => null]);
        $unconfigured = $this->countQueries($this->user);

        $this->assertSame([], array_filter($configured, fn (string $connection) => str_starts_with($connection, 'sap_')));
        $this->assertSame($unconfigured, $configured);
    }

    /**
     * @return list<string> The connection of each query of one home page.
     */
    private function countQueries(User $user): array
    {
        $connections = [];
        DB::listen(function (QueryExecuted $query) use (&$connections) {
            $connections[] = $query->connectionName;
        });

        $this->actingAs($user->fresh())->get('/')->assertOk();

        app('events')->forget(QueryExecuted::class);

        return $connections;
    }
}
