<?php

namespace Tests\Feature\Admin;

use App\Models\App;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use App\Usage\UsageReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UsageReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private App $cartera;

    private App $portafolio;

    private App $camaras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        activity()->withoutLogs(function () {
            $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'slug' => 'segurex']);
            $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'slug' => 'derivados']);
            $this->cartera = App::factory()->forCompanies($this->segurex)->create(['name' => 'Visualizador de cartera', 'slug' => 'cartera']);
            $this->portafolio = App::factory()->forCompanies($this->derivados)->create(['name' => 'Portafolio de productos', 'slug' => 'portafolio']);
            $this->camaras = App::factory()->local()->forCompanies($this->segurex)->create(['name' => 'Cámaras', 'slug' => 'camaras']);
        });

        // The admin never signs in nor opens apps here: not an active user.
        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');
    }

    private function person(Company $company, ?string $lastLogin = null): User
    {
        return User::factory()->microsoft($company)->create([
            'last_login_at' => $lastLogin ? CarbonImmutable::parse($lastLogin, 'America/Bogota') : null,
        ]);
    }

    private function launch(User $user, App $app, string $at, string $device = 'desktop'): void
    {
        AppLaunch::create([
            'user_id' => $user->id,
            'app_id' => $app->id,
            'company_id' => $user->company_id,
            'device' => $device,
            'launched_at' => CarbonImmutable::parse($at, 'America/Bogota'),
        ]);
    }

    private function report(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.usage.index', $query));
    }

    // --- Access -------------------------------------------------------------

    public function test_only_super_and_portal_admins_can_open_it(): void
    {
        $this->get(route('admin.usage.index'))->assertRedirect(route('login'));

        $super = User::factory()->create();
        $super->assignRole('super_admin');
        $this->actingAs($super)->get(route('admin.usage.index'))->assertOk();

        $editor = User::factory()->create();
        $editor->assignRole('announcements_editor');
        $this->actingAs($editor)->get(route('admin.usage.index'))->assertForbidden();
    }

    public function test_the_sidebar_links_to_the_report_and_there_is_no_sample_data(): void
    {
        $this->report()
            ->assertOk()
            ->assertSee('href="'.route('admin.usage.index').'" aria-current="page"', false)
            ->assertDontSeeText('Datos de ejemplo')
            ->assertDontSeeText('DATOS DE EJEMPLO');
    }

    // --- Active users -----------------------------------------------------

    public function test_active_users_per_company_count_launches_or_sign_ins_inside_bogota_days(): void
    {
        // Period 7 days = 2026-09-30 00:00 .. now, Bogotá.
        $inByLaunch = $this->person($this->segurex);
        $this->launch($inByLaunch, $this->cartera, '2026-09-30 00:05');
        $inByLaunch2 = $this->person($this->segurex);
        $this->launch($inByLaunch2, $this->cartera, '2026-10-06 09:00');
        $this->launch($inByLaunch2, $this->cartera, '2026-10-06 09:30');
        $inBySignIn = $this->person($this->segurex, '2026-10-01 08:00');
        $outLaunch = $this->person($this->segurex);
        $this->launch($outLaunch, $this->cartera, '2026-09-29 23:55');
        $this->person($this->segurex, '2026-09-29 23:59');
        $derivados = $this->person($this->derivados, '2026-10-06 07:00');
        User::factory()->emergency()->create(['last_login_at' => now()]);
        activity()->withoutLogs(fn () => Company::factory()->inactive()->create(['name' => 'Empresa Cerrada', 'short_name' => 'Cerrada']));

        $data = app(UsageReport::class)->build(7);
        $this->assertSame(['Segurex' => 3, 'Derivados' => 1], $data->activeUsers);

        $this->report(['periodo' => '7'])
            ->assertSeeTextInOrder(['Usuarios activos · Segurex', '3', 'Usuarios activos · Derivados', '1'])
            ->assertSeeText('entraron al portal o abrieron una app al menos una vez en 7 días')
            ->assertDontSeeText('Cerrada');

        // 30 days include the earlier ones.
        $this->assertSame(['Segurex' => 5, 'Derivados' => 1], app(UsageReport::class)->build(30)->activeUsers);
    }

    // --- Launches and devices -------------------------------------------

    public function test_total_launches_and_device_split(): void
    {
        $ana = $this->person($this->segurex);
        $this->launch($ana, $this->cartera, '2026-10-06 08:00', 'desktop');
        $this->launch($ana, $this->cartera, '2026-10-05 08:00', 'desktop');
        $this->launch($ana, $this->cartera, '2026-10-04 08:00', 'tablet');
        $this->launch($ana, $this->camaras, '2026-10-03 08:00', 'phone');
        $this->launch($ana, $this->cartera, '2026-09-29 23:59', 'phone');

        $data = app(UsageReport::class)->build(7);
        $this->assertSame(4, $data->totalLaunches);
        $this->assertSame(['desktop' => 50, 'tablet' => 25, 'phone' => 25], $data->devicePercentages);

        $this->report(['periodo' => '7'])
            ->assertSeeTextInOrder(['Aperturas de apps', '4'])
            ->assertSeeTextInOrder(['Desde dónde abren las apps', '50 %', 'escritorio'])
            ->assertSee('aria-label="Escritorio 50 %, tablet 25 %, celular 25 %"', false)
            ->assertSeeTextInOrder(['Escritorio', '50 %', 'Tablet', '25 %', 'Celular', '25 %']);
    }

    public function test_percentages_always_add_up_to_one_hundred(): void
    {
        $ana = $this->person($this->segurex);
        $this->launch($ana, $this->cartera, '2026-10-06 08:00', 'desktop');
        $this->launch($ana, $this->cartera, '2026-10-06 08:01', 'tablet');
        $this->launch($ana, $this->cartera, '2026-10-06 08:02', 'phone');

        $this->assertSame(100, array_sum(app(UsageReport::class)->build(7)->devicePercentages));
    }

    public function test_an_empty_period_says_so(): void
    {
        $this->report()
            ->assertOk()
            ->assertSeeText('Nadie abrió apps desde el portal en este periodo.');
    }

    // --- Most opened apps ---------------------------------------------

    public function test_most_opened_apps_are_sorted_and_the_table_has_the_same_numbers(): void
    {
        $ana = $this->person($this->segurex);
        $luis = $this->person($this->derivados);
        foreach (range(1, 3) as $i) {
            $this->launch($ana, $this->cartera, "2026-10-0{$i} 08:00");
        }
        $this->launch($luis, $this->portafolio, '2026-10-02 08:00');
        $this->launch($ana, $this->camaras, '2026-10-02 08:00');
        $this->launch($ana, $this->camaras, '2026-10-03 08:00');

        $data = app(UsageReport::class)->build(30);
        $this->assertSame(
            [['Visualizador de cartera', 3], ['Cámaras', 2], ['Portafolio de productos', 1]],
            array_map(fn ($row) => [$row['name'], $row['launches']], $data->topApps),
        );

        $response = $this->report();
        $html = $response->getContent();

        $bars = substr($html, strpos($html, 'id="usage-bars"'), strpos($html, 'id="usage-table"') - strpos($html, 'id="usage-bars"'));
        $table = substr($html, strpos($html, 'id="usage-table"'));

        foreach ([$bars, $table] as $part) {
            $this->assertMatchesRegularExpression('/Visualizador de cartera.*?>3<.*?Cámaras.*?>2<.*?Portafolio de productos.*?>1</s', $part);
        }

        $response
            ->assertSee('aria-label="Visualizador de cartera: 3 aperturas"', false)
            ->assertSee('title="3 aperturas"', false)
            ->assertSee('style="width: 100%"', false)
            ->assertSee('style="width: 33.3%"', false)
            ->assertSeeText('Ver como tabla')
            ->assertSeeText('En las apps locales cuenta las veces que se abrió su ficha.');
    }

    // --- Unused apps ---------------------------------------------------

    public function test_unused_apps_ignore_the_selected_period_and_hidden_apps(): void
    {
        $ana = $this->person($this->segurex);
        // 90 days = 2026-07-09 00:00 .. now.
        $this->launch($ana, $this->cartera, '2026-07-09 00:01');
        $this->launch($ana, $this->portafolio, '2026-07-08 23:59');
        activity()->withoutLogs(fn () => App::factory()->hidden()->create(['name' => 'App Oculta']));

        $data = app(UsageReport::class)->build(7);
        $this->assertSame(['Cámaras', 'Portafolio de productos'], array_column($data->unusedApps, 'name'));

        $this->report(['periodo' => '7'])
            ->assertSeeTextInOrder(['Sin uso en 90 días', 'Cámaras', 'nunca se ha abierto', 'Portafolio de productos', 'última vez el 8 de julio de 2026'])
            ->assertDontSeeText('App Oculta');
    }

    public function test_no_unused_apps_shows_a_positive_message(): void
    {
        $ana = $this->person($this->segurex);
        foreach ([$this->cartera, $this->portafolio, $this->camaras] as $app) {
            $this->launch($ana, $app, '2026-09-01 08:00');
        }

        $this->report()->assertSeeText('Todas las apps se usaron al menos una vez en 90 días.');
    }

    // --- Period and queries ---------------------------------------------

    public function test_the_period_is_a_bookmarkable_parameter(): void
    {
        $this->report()->assertSee('href="'.route('admin.usage.index', ['periodo' => '7']).'"', false)
            ->assertSee('aria-current="true"', false)
            ->assertSeeText('en 30 días');

        $this->report(['periodo' => '90'])->assertSeeText('en 90 días');
        $this->report(['periodo' => '365'])->assertOk()->assertSeeText('en 30 días');
    }

    public function test_the_query_count_does_not_grow_with_the_data(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->launch($this->person($this->segurex), $this->cartera, '2026-10-01 08:00');
        $queries(); // Warms the permission cache.
        $few = $queries();

        foreach (range(1, 15) as $i) {
            activity()->withoutLogs(fn () => App::factory()->forCompanies($this->segurex)->create());
            $user = $this->person($i % 2 ? $this->segurex : $this->derivados, '2026-10-02 08:00');
            $this->launch($user, $this->portafolio, '2026-10-03 08:00', ['desktop', 'tablet', 'phone'][$i % 3]);
        }

        $this->assertSame($few, $queries());
        $this->assertLessThanOrEqual(15, $few);
    }
}
