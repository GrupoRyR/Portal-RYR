<?php

namespace Tests\Feature\ItStatus;

use App\Catalog\AppCatalog;
use App\ItStatus\ItStatusBoard;
use App\ItStatus\ItStatusItem;
use App\ItStatus\ItStatusLevel;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItStatusBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $sdc;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['slug' => 'segurex']);
        $this->sdc = Company::factory()->create(['slug' => 'sdc']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_everything_is_fine_when_nothing_is_reported(): void
    {
        $this->visibleApp('Cartera');
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo']);

        $status = $this->board();

        $this->assertTrue($status->available);
        $this->assertSame(ItStatusLevel::Operational, $status->level);
        $this->assertTrue($status->allGood());
        $this->assertSame([], $status->apps);
        $this->assertSame([], $status->services);
        $this->assertSame('Todo funciona con normalidad', $status->level->headline());
    }

    public function test_an_app_in_maintenance_is_listed_as_degraded_with_its_message_and_end(): void
    {
        $app = $this->visibleApp('Cartera', App::factory()->maintenance('Actualizando a la versión 2.'));

        $status = $this->board();

        $this->assertSame(ItStatusLevel::Degraded, $status->level);
        $this->assertCount(1, $status->apps);
        $item = $status->apps[0];
        $this->assertSame(ItStatusItem::KIND_APP, $item->kind);
        $this->assertSame('Cartera', $item->name);
        $this->assertSame($app->slug, $item->slug);
        $this->assertSame(ItStatusLevel::Degraded, $item->level);
        $this->assertSame('En mantenimiento', $item->label);
        $this->assertSame('Actualizando a la versión 2.', $item->message);
        $this->assertTrue($item->until->equalTo($app->maintenance_until));
    }

    public function test_an_app_reported_down_makes_the_whole_board_down(): void
    {
        $down = $this->visibleApp('SAP');
        $down->forceFill(['external_status' => 'down', 'external_status_message' => 'SAP no responde', 'external_status_at' => now()])->save();
        $degraded = $this->visibleApp('Cartera');
        $degraded->forceFill(['external_status' => 'degraded', 'external_status_at' => now()])->save();

        $status = $this->board();

        $this->assertSame(ItStatusLevel::Down, $status->level);
        $this->assertSame(['Cartera', 'SAP'], array_map(fn (ItStatusItem $item) => $item->name, $status->apps), 'Apps keep the catalog order.');
        $byName = collect($status->apps)->keyBy('name');
        $this->assertSame(ItStatusLevel::Down, $byName['SAP']->level);
        $this->assertSame('Con fallas', $byName['SAP']->label);
        $this->assertSame('SAP no responde', $byName['SAP']->message);
        $this->assertNull($byName['SAP']->until);
        $this->assertSame(ItStatusLevel::Degraded, $byName['Cartera']->level);
    }

    public function test_an_expired_or_operational_report_is_not_listed(): void
    {
        app(PortalSettings::class)->update([PortalSettings::API_STATUS_TTL => 4], null);
        $old = $this->visibleApp('SAP');
        $old->forceFill(['external_status' => 'down', 'external_status_at' => now()->subHours(5)])->save();
        $fine = $this->visibleApp('Cartera');
        $fine->forceFill(['external_status' => 'operational', 'external_status_at' => now()])->save();

        $status = $this->board();

        $this->assertSame([], $status->apps);
        $this->assertSame(ItStatusLevel::Operational, $status->level);
    }

    public function test_maintenance_wins_over_a_reported_failure_like_the_card(): void
    {
        $app = $this->visibleApp('SAP', App::factory()->maintenance());
        $app->forceFill(['external_status' => 'down', 'external_status_at' => now()])->save();

        $item = $this->board()->apps[0];

        $this->assertSame('En mantenimiento', $item->label);
        $this->assertSame(ItStatusLevel::Degraded, $item->level);
    }

    public function test_apps_the_person_cannot_see_are_never_listed(): void
    {
        // Another company's app, an app without a rule for Ana, a hidden one.
        $theirs = App::factory()->maintenance()->forCompanies($this->sdc)->create(['name' => 'De SDC']);
        AppAccessRule::forCompany($theirs, $this->sdc);
        App::factory()->maintenance()->forCompanies($this->segurex)->create(['name' => 'Sin regla']);
        $hidden = App::factory()->hidden()->forCompanies($this->segurex)->create(['name' => 'Oculta']);
        $hidden->forceFill(['external_status' => 'down', 'external_status_at' => now()])->save();
        AppAccessRule::forCompany($hidden, $this->segurex);

        $status = $this->board();

        $this->assertSame([], $status->apps);
        $this->assertSame(ItStatusLevel::Operational, $status->level);
    }

    public function test_only_the_companys_services_that_are_not_operational_are_listed_worst_first(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'position' => 1]);
        ItService::factory()->for($this->segurex)->degraded('Lenta en la sede norte')->create(['name' => 'VPN', 'position' => 2]);
        ItService::factory()->for($this->segurex)->down('Sin línea')->create(['name' => 'Telefonía', 'position' => 3]);
        ItService::factory()->for($this->segurex)->degraded()->create(['name' => 'Internet', 'position' => 4]);
        ItService::factory()->for($this->sdc)->down()->create(['name' => 'Impresoras']);

        $status = $this->board();

        $this->assertSame(ItStatusLevel::Down, $status->level);
        $this->assertSame(['Telefonía', 'VPN', 'Internet'], array_map(fn (ItStatusItem $item) => $item->name, $status->services));
        $this->assertSame(ItStatusItem::KIND_SERVICE, $status->services[0]->kind);
        $this->assertSame('Fuera de servicio', $status->services[0]->label);
        $this->assertSame('Sin línea', $status->services[0]->message);
        $this->assertSame('Con fallas', $status->services[1]->label);
        $this->assertNull($status->services[0]->slug);
    }

    public function test_a_degraded_service_alone_makes_the_board_degraded(): void
    {
        ItService::factory()->for($this->segurex)->degraded()->create(['name' => 'VPN']);

        $status = $this->board();

        $this->assertSame(ItStatusLevel::Degraded, $status->level);
        $this->assertFalse($status->allGood());
        $this->assertSame('Algunos servicios tienen fallas', $status->level->headline());
    }

    public function test_the_other_company_sees_only_its_own_board(): void
    {
        ItService::factory()->for($this->segurex)->down()->create(['name' => 'Correo']);
        $sdcUser = User::factory()->microsoft($this->sdc)->create();

        $status = app(ItStatusBoard::class)->for($sdcUser);

        $this->assertSame([], $status->services);
        $this->assertSame(ItStatusLevel::Operational, $status->level);
    }

    public function test_people_without_an_active_company_get_an_empty_board(): void
    {
        ItService::factory()->for($this->segurex)->down()->create();
        $inactive = Company::factory()->create(['is_active' => false]);
        ItService::factory()->for($inactive)->down()->create();

        $people = [
            'without company' => User::factory()->create(['company_id' => null]),
            'inactive company' => User::factory()->microsoft($inactive)->create(),
            'emergency' => User::factory()->emergency()->create(),
        ];

        foreach ($people as $who => $user) {
            $status = app(ItStatusBoard::class)->for($user);

            $this->assertFalse($status->available, $who);
            $this->assertSame(ItStatusLevel::Operational, $status->level, $who);
            $this->assertSame([], $status->services, $who);
            $this->assertSame([], $status->apps, $who);
        }
    }

    public function test_report_a_problem_uses_the_support_link_only_when_it_is_configured(): void
    {
        $this->assertNull($this->board()->reportHref);

        app(PortalSettings::class)->update([PortalSettings::SUPPORT_URL => 'https://soporte.segurex.com.co/tickets/nuevo'], null);

        $this->assertSame(route('support'), $this->board()->reportHref);
    }

    public function test_it_reuses_the_visible_apps_and_runs_one_query_whatever_the_number_of_services(): void
    {
        $this->visibleApp('Cartera', App::factory()->maintenance());
        ItService::factory()->for($this->segurex)->down()->create();
        $visible = app(AppCatalog::class)->visibleApps($this->ana);
        $board = app(ItStatusBoard::class);
        $board->for($this->ana, $visible); // Warms the settings cache.

        $few = $this->countQueries(fn () => $board->for($this->ana, $visible));
        ItService::factory()->for($this->segurex)->down()->count(15)->create();
        $many = $this->countQueries(fn () => $board->for($this->ana, $visible));

        $this->assertSame(1, $few);
        $this->assertSame($few, $many);
        $this->assertCount(16, $board->for($this->ana, $visible)->services);
    }

    public function test_the_levels_compare_by_severity(): void
    {
        $this->assertSame(ItStatusLevel::Down, ItStatusLevel::worst([ItStatusLevel::Degraded, ItStatusLevel::Down, ItStatusLevel::Operational]));
        $this->assertSame(ItStatusLevel::Degraded, ItStatusLevel::worst([ItStatusLevel::Operational, ItStatusLevel::Degraded]));
        $this->assertSame(ItStatusLevel::Operational, ItStatusLevel::worst([]));
        $this->assertSame('Hay servicios fuera de servicio', ItStatusLevel::Down->headline());
    }

    // --- Helpers ------------------------------------------------------------

    private function board()
    {
        return app(ItStatusBoard::class)->for($this->ana);
    }

    private function visibleApp(string $name, $factory = null): App
    {
        $app = ($factory ?? App::factory())->forCompanies($this->segurex)->create(['name' => $name]);
        AppAccessRule::forCompany($app, $this->segurex);

        return $app;
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
