<?php

namespace Tests\Feature\Management;

use App\Auth\MicrosoftLogin;
use App\Management\ManagementKpi;
use App\Management\ManagementPanel;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The read side of the management panel (odd/tasks/panel-gerencial.md):
 * one company's current set of figures in one query, and the
 * `viewManagementPanel` ability, true only for a «Gerencia» account of
 * that same company.
 */
class ManagementPanelTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'bbbbbbbb-0000-4000-8000-000000000001';

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 06:00', 'America/Bogota'));

        $this->segurex = Company::factory()->withManagementGroup(self::GROUP)->create(['slug' => 'segurex']);
        $this->derivados = Company::factory()->withManagementGroup('bbbbbbbb-0000-4000-8000-000000000002')->create(['slug' => 'derivados']);
    }

    private function figures(Company $company, array $keys, array $attributes = []): void
    {
        foreach (array_values($keys) as $index => $key) {
            CompanyKpi::factory()->create([
                'company_id' => $company->id,
                'position' => $index + 1,
                'key' => $key,
                'label' => ucfirst($key).' '.$company->slug,
                'as_of' => CarbonImmutable::parse('2026-11-04 23:59', 'America/Bogota'),
                ...$attributes,
            ]);
        }
    }

    /**
     * A «Gerencia» account of the company: the role and the stored
     * membership of the company's security group.
     */
    private function manager(Company $company, string $groupId = self::GROUP): User
    {
        $user = User::factory()->microsoft($company)->create();
        $user->assignRole(MicrosoftLogin::MANAGEMENT);
        $user->syncGroups([['id' => $groupId, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);

        return $user->fresh();
    }

    private function panel(): ManagementPanel
    {
        return app(ManagementPanel::class);
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    // --- Read model -----------------------------------------------------------

    public function test_a_company_set_is_read_in_one_query_in_order(): void
    {
        // Inserted out of order on purpose: the position decides.
        $this->figures($this->segurex, ['pedidos'], ['position' => 3, 'value' => 340, 'unit' => null, 'goal' => null]);
        $this->figures($this->segurex, ['ventas'], ['position' => 1, 'value' => 1250000000, 'unit' => 'COP', 'goal' => 1500000000, 'note' => 'Sin IVA']);
        $this->figures($this->segurex, ['cartera'], ['position' => 2, 'value' => 12.5, 'unit' => '%', 'goal' => null]);

        $snapshot = null;
        $queries = $this->queriesDuring(function () use (&$snapshot) {
            $snapshot = $this->panel()->forCompany($this->segurex);
        });

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertSame('Octubre', $snapshot->periodLabel);
        $this->assertSame('SAP Business One', $snapshot->sourceLabel);
        $this->assertTrue($snapshot->asOf->equalTo(CarbonImmutable::parse('2026-11-04 23:59', 'America/Bogota')));
        $this->assertSame(['ventas', 'cartera', 'pedidos'], array_map(fn (ManagementKpi $kpi) => $kpi->key, $snapshot->items));

        [$ventas, $cartera, $pedidos] = $snapshot->items;
        $this->assertSame('Ventas segurex', $ventas->label);
        $this->assertSame(1250000000.0, $ventas->value);
        $this->assertSame(1500000000.0, $ventas->goal);
        $this->assertSame('COP', $ventas->unit);
        $this->assertSame('Sin IVA', $ventas->note);
        $this->assertEqualsWithDelta(0.8333, $ventas->ratio(), 0.0001);
        $this->assertNull($cartera->ratio());
        $this->assertNull($pedidos->unit);
    }

    public function test_the_company_may_be_given_by_its_id(): void
    {
        $this->figures($this->segurex, ['ventas']);

        $this->assertSame(['ventas'], array_map(fn (ManagementKpi $kpi) => $kpi->key, $this->panel()->forCompany($this->segurex->id)->items));
    }

    public function test_other_companies_figures_never_appear(): void
    {
        $this->figures($this->segurex, ['ventas', 'cartera']);
        $this->figures($this->derivados, ['ventas', 'pedidos', 'inventario']);

        $segurex = $this->panel()->forCompany($this->segurex);
        $this->assertSame(['Ventas segurex', 'Cartera segurex'], array_map(fn (ManagementKpi $kpi) => $kpi->label, $segurex->items));

        $viewer = $this->panel()->forViewer($this->manager($this->segurex));
        $this->assertSame(['Ventas segurex', 'Cartera segurex'], array_map(fn (ManagementKpi $kpi) => $kpi->label, $viewer->items));

        $this->assertNull($this->panel()->forViewer($this->manager($this->derivados, self::GROUP)));
    }

    public function test_a_company_without_figures_has_no_panel(): void
    {
        $this->figures($this->derivados, ['ventas']);

        $this->assertNull($this->panel()->forCompany($this->segurex));
        $this->assertNull($this->panel()->forViewer($this->manager($this->segurex)));
    }

    public function test_the_viewer_read_adds_one_query_to_the_ability_check(): void
    {
        $this->figures($this->segurex, ['ventas', 'cartera', 'pedidos']);
        $manager = $this->manager($this->segurex);
        $manager->load(['company', 'roles']);

        $queries = $this->queriesDuring(fn () => $this->panel()->forViewer($manager));

        $this->assertCount(1, array_filter($queries, fn (string $sql) => str_contains($sql, '"company_kpis"')), implode("\n", $queries));
    }

    // --- Ability --------------------------------------------------------------

    public function test_a_gerencia_account_sees_its_own_company_only(): void
    {
        $manager = $this->manager($this->segurex);

        $this->assertTrue(Gate::forUser($manager)->allows(ManagementPanel::ABILITY, $this->segurex));
        $this->assertTrue($manager->can(ManagementPanel::ABILITY, $this->segurex));
        $this->assertTrue(Gate::forUser($manager)->allows(ManagementPanel::ABILITY));
        $this->assertFalse(Gate::forUser($manager)->allows(ManagementPanel::ABILITY, $this->derivados));
    }

    public function test_the_role_alone_is_not_enough(): void
    {
        $user = User::factory()->microsoft($this->segurex)->create();
        $user->assignRole(MicrosoftLogin::MANAGEMENT);

        $this->assertFalse(Gate::forUser($user)->allows(ManagementPanel::ABILITY, $this->segurex));
    }

    public function test_membership_alone_is_not_enough(): void
    {
        $user = $this->manager($this->segurex);
        $user->removeRole(MicrosoftLogin::MANAGEMENT);

        $this->assertFalse(Gate::forUser($user->fresh())->allows(ManagementPanel::ABILITY, $this->segurex));
    }

    public function test_changing_or_clearing_the_company_group_closes_the_panel_at_once(): void
    {
        $manager = $this->manager($this->segurex);

        $this->segurex->update(['management_group_id' => 'bbbbbbbb-0000-4000-8000-000000000009']);
        $this->assertFalse(Gate::forUser($manager->fresh())->allows(ManagementPanel::ABILITY, $this->segurex->fresh()));

        $this->segurex->update(['management_group_id' => null]);
        $this->assertFalse(Gate::forUser($manager->fresh())->allows(ManagementPanel::ABILITY, $this->segurex->fresh()));
    }

    public function test_an_inactive_company_has_no_panel(): void
    {
        $manager = $this->manager($this->segurex);
        $this->segurex->update(['is_active' => false]);

        $this->assertFalse(Gate::forUser($manager->fresh())->allows(ManagementPanel::ABILITY, $this->segurex->fresh()));
    }

    public function test_admins_without_the_role_do_not_see_it(): void
    {
        foreach (['super_admin', 'portal_admin', 'announcements_editor'] as $role) {
            $user = User::factory()->microsoft($this->segurex)->create();
            $user->assignRole($role);
            $user->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => false]]);

            $this->assertFalse(Gate::forUser($user)->allows(ManagementPanel::ABILITY, $this->segurex), $role);
        }
    }

    public function test_emergency_accounts_never_see_it(): void
    {
        $user = User::factory()->emergency()->create();
        $user->assignRole(MicrosoftLogin::MANAGEMENT);

        $this->assertFalse(Gate::forUser($user)->allows(ManagementPanel::ABILITY, $this->segurex));
        $this->assertFalse(Gate::forUser($user)->allows(ManagementPanel::ABILITY));
    }

    public function test_the_viewer_read_is_empty_without_the_ability(): void
    {
        $this->figures($this->segurex, ['ventas']);
        $user = User::factory()->microsoft($this->segurex)->create();

        $this->assertNull($this->panel()->forViewer($user));
    }
}
