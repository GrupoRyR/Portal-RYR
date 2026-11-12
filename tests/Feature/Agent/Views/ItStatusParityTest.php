<?php

namespace Tests\Feature\Agent\Views;

use App\ItStatus\ItStatusBoard;
use App\ItStatus\ItStatusItem;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Views\Concerns\BuildsAgentWorld;
use Tests\TestCase;

/**
 * v_estado_ti against ItStatusBoard (what «Estado de TI» shows a person of
 * the company), with failure reports at the edges of the 1, 2 and 4 hour
 * windows of Configuración.
 */
class ItStatusParityTest extends TestCase
{
    use BuildsAgentWorld;
    use RefreshDatabase;

    /** The only app the company gets that no access rule lets a person open (PHP-only rule). */
    private const WITHOUT_RULE = 'down-without-rule';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCompanies();
        $this->seedItStatus();
        $this->freeze('2026-11-12 10:00:00');
    }

    /** @return array<string, array{string, string}> */
    public static function companies(): array
    {
        return ['segurex' => ['sgx', 'SGX'], 'derivados' => ['sdc', 'SDC']];
    }

    /** @return array<string, array{int, string, string}> */
    public static function windows(): array
    {
        $cases = [];
        foreach ([1, 2, 4] as $hours) {
            foreach (self::companies() as $name => [$property, $code]) {
                $cases["{$hours}h {$name}"] = [$hours, $property, $code];
            }
        }

        return $cases;
    }

    #[DataProvider('windows')]
    public function test_the_view_lists_what_the_board_lists(int $hours, string $property, string $code): void
    {
        app(PortalSettings::class)->update([PortalSettings::API_STATUS_TTL => $hours], null);
        $status = app(ItStatusBoard::class)->for($this->userOf($this->{$property}));
        $rows = $this->rows('v_estado_ti', $code);

        $apps = $rows->where('tipo', 'app')->reject(fn ($row) => $row->nombre === self::WITHOUT_RULE)->keyBy('nombre');
        $expectedApps = collect($status->apps)->keyBy('name');

        $this->assertSame($expectedApps->keys()->sort()->values()->all(), $apps->keys()->sort()->values()->all(), "{$hours}h {$code}");
        foreach ($expectedApps as $name => $item) {
            $this->assertSame($this->estadoOf($item), $apps[$name]->estado, $name);
            $this->assertSame($item->message, $apps[$name]->mensaje, $name);
        }

        $services = $rows->where('tipo', 'servicio')->reject(fn ($row) => $row->estado === 'operational')->keyBy('nombre');
        $expectedServices = collect($status->services)->keyBy('name');

        $this->assertSame($expectedServices->keys()->sort()->values()->all(), $services->keys()->sort()->values()->all(), $code);
        foreach ($expectedServices as $name => $item) {
            $this->assertSame($item->level->value, $services[$name]->estado, $name);
            $this->assertSame($item->message, $services[$name]->mensaje, $name);
        }
    }

    public function test_the_edges_of_the_report_window(): void
    {
        $at = function (int $hours): array {
            app(PortalSettings::class)->update([PortalSettings::API_STATUS_TTL => $hours], null);

            return $this->rows('v_estado_ti', 'SGX')->where('tipo', 'app')->pluck('nombre')->all();
        };

        $two = $at(2);
        $this->assertContains('report-just-inside', $two);
        $this->assertNotContains('report-at-2h', $two);
        $this->assertNotContains('report-3h', $two);

        $four = $at(4);
        $this->assertContains('report-at-2h', $four);
        $this->assertContains('report-3h', $four);
        $this->assertNotContains('report-5h', $four);

        $one = $at(1);
        $this->assertContains('degraded-both', $one);
        $this->assertNotContains('down-sgx-only', $one);
        $this->assertNotContains('report-just-inside', $one);
        $this->assertNotContains('report-1h30', $one);
    }

    public function test_without_a_stored_setting_the_default_hours_apply(): void
    {
        $this->assertSame(0, DB::table('portal_settings')->count());
        $names = $this->rows('v_estado_ti', 'SGX')->where('tipo', 'app')->pluck('nombre')->all();

        $this->assertContains('report-1h30', $names);
        $this->assertNotContains('report-at-2h', $names);
    }

    public function test_the_services_come_with_every_status_and_an_unknown_one_counts_as_degraded(): void
    {
        $services = $this->rows('v_estado_ti', 'SGX')->where('tipo', 'servicio')->pluck('estado', 'nombre')->all();

        $this->assertSame(['VPN' => 'operational', 'Correo' => 'degraded', 'Internet' => 'down', 'Telefonía' => 'degraded'], $services);
        $this->assertSame(['VPN' => 'operational', 'Correo' => 'down'], $this->rows('v_estado_ti', 'SDC')->where('tipo', 'servicio')->pluck('estado', 'nombre')->all());
    }

    public function test_an_app_the_company_gets_but_no_rule_opens_is_listed_by_the_view_only(): void
    {
        $this->assertContains(self::WITHOUT_RULE, $this->rows('v_estado_ti', 'SGX')->pluck('nombre')->all());

        $status = app(ItStatusBoard::class)->for($this->userOf($this->sgx));
        $this->assertNotContains(self::WITHOUT_RULE, array_map(fn (ItStatusItem $item) => $item->slug, $status->apps));
    }

    public function test_maintenance_wins_over_a_report_and_hidden_or_unrelated_apps_stay_out(): void
    {
        $apps = $this->rows('v_estado_ti', 'SGX')->where('tipo', 'app')->keyBy('nombre');

        $this->assertSame('maintenance', $apps['maintenance-and-down']->estado);
        $this->assertSame('Gana', $apps['maintenance-and-down']->mensaje);
        $this->assertSame('maintenance', $apps['maintenance-all']->estado);
        $this->assertSame('Actualizando', $apps['maintenance-all']->mensaje);
        $this->assertSame('2026-11-12 09:00:00', $apps['down-sgx-only']->actualizado);

        foreach (['hidden-maintenance', 'maintenance-sdc', 'maintenance-nobody', 'maintenance-other-only', 'healthy-all', 'report-operational', 'report-unknown'] as $slug) {
            $this->assertArrayNotHasKey($slug, $apps->all(), $slug);
        }
        $this->assertArrayHasKey('maintenance-sdc', $this->rows('v_estado_ti', 'SDC')->where('tipo', 'app')->keyBy('nombre')->all());
    }

    public function test_a_company_outside_the_mapping_or_inactive_gets_no_row(): void
    {
        $this->assertEqualsCanonicalizing(['SGX', 'SDC'], DB::table('v_estado_ti')->distinct()->pluck('empresa')->all());

        $this->sdc->forceFill(['is_active' => false])->save();
        $this->assertSame(0, $this->rows('v_estado_ti', 'SDC')->count());
        $this->assertGreaterThan(0, $this->rows('v_estado_ti', 'SGX')->count());
    }

    /** The view says «maintenance» where the board says degraded with the maintenance chip. */
    private function estadoOf(ItStatusItem $item): string
    {
        return $item->label === 'En mantenimiento' ? 'maintenance' : $item->level->value;
    }
}
