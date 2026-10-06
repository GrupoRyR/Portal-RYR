<?php

namespace Tests\Feature\Admin;

use App\Api\StatusReporter;
use App\Audit\AuditFeed;
use App\Audit\AuditFilters;
use App\Audit\SecurityLog;
use App\Models\App;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private User $ana;

    private User $luis;

    private App $cartera;

    private App $portafolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        // Fixtures are created without audit entries, so each test controls
        // exactly what the page lists.
        activity()->withoutLogs(function () {
            $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'slug' => 'segurex', 'color_primary' => '#FF671F']);
            $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'slug' => 'derivados']);
            $this->cartera = App::factory()->forCompanies($this->segurex)->create(['name' => 'Visualizador de cartera', 'slug' => 'cartera']);
            $this->portafolio = App::factory()->forCompanies($this->derivados)->create(['name' => 'Portafolio de productos', 'slug' => 'portafolio']);
        });

        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro', 'email' => 'jhon@segurex.com']);
        $this->admin->assignRole('super_admin');
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez', 'email' => 'ana@segurex.com']);
        $this->luis = User::factory()->microsoft($this->derivados)->create(['name' => 'Luis Mora', 'email' => 'luis@derivados.com']);
    }

    private function launch(User $user, App $app, string $device = 'desktop', ?CarbonImmutable $at = null): AppLaunch
    {
        return AppLaunch::create([
            'user_id' => $user->id,
            'app_id' => $app->id,
            'company_id' => $user->company_id,
            'device' => $device,
            'launched_at' => $at ?? now(),
        ]);
    }

    /**
     * A change made by $user on the company (an `updated` activity entry).
     */
    private function changeCompany(User $user, array $attributes): void
    {
        $this->actingAs($user);
        $this->segurex->update($attributes);
        auth()->logout();
    }

    private function audit(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.audit.index', $query));
    }

    // --- Access -------------------------------------------------------------

    public function test_only_super_and_portal_admins_can_open_it(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));

        $portal = User::factory()->create();
        $portal->assignRole('portal_admin');
        $this->actingAs($portal)->get(route('admin.audit.index'))->assertOk()->assertSeeText('Auditoría');

        $editor = User::factory()->create();
        $editor->assignRole('announcements_editor');
        $this->actingAs($editor)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.audit.export'))->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_the_sidebar_links_to_the_audit(): void
    {
        $this->audit()->assertSee('href="'.route('admin.audit.index').'" aria-current="page"', false);
    }

    // --- Sources ------------------------------------------------------------

    public function test_changes_launches_and_security_events_are_listed_newest_first(): void
    {
        $this->travel(-3)->hours();
        $this->changeCompany($this->admin, ['color_primary' => '#C4470F']);
        $this->travelBack();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'America/Bogota'));
        SecurityLog::microsoftSignIn($this->ana, 'Mozilla/5.0 (Windows NT 10.0)', ['added' => [], 'removed' => []]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:58', 'America/Bogota'));
        $this->launch($this->ana, $this->cartera, 'phone');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->audit()
            ->assertOk()
            ->assertSeeTextInOrder([
                'hace 2 minutos', 'Ana Pérez', 'Abrió app', 'Visualizador de cartera', 'Celular',
                'hace 1 hora', 'Ana Pérez', 'Seguridad', 'Entró con Microsoft',
                'hace 3 horas', 'Jhon Castro', 'Cambio', 'Cambió la empresa Segurex Latam',
            ]);
    }

    public function test_entries_without_a_causer_are_shown_as_the_system(): void
    {
        SecurityLog::emergencyFailed('alguien@segurex.com', 'credentials', 'invalid');

        $this->audit()->assertSeeTextInOrder(['Sistema', 'Seguridad', 'alguien@segurex.com']);
    }

    public function test_older_entries_show_a_date_in_bogota_time(): void
    {
        $this->launch($this->ana, $this->cartera, at: CarbonImmutable::parse('2026-10-03 18:30', 'America/Bogota'));

        $this->audit()->assertSeeText('3 oct. 2026, 18:30');
    }

    public function test_change_rows_expand_to_a_before_and_after_table(): void
    {
        $this->changeCompany($this->admin, ['color_primary' => '#C4470F', 'short_name' => 'Segurex Co']);

        DB::table('activity_log')->insert([
            'log_name' => 'companies',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => $this->segurex->getMorphClass(),
            'subject_id' => $this->segurex->id,
            'causer_type' => $this->admin->getMorphClass(),
            'causer_id' => $this->admin->id,
            'properties' => json_encode(['old' => ['campo_nuevo' => 'a'], 'attributes' => ['campo_nuevo' => 'b']]),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->audit()
            ->assertSee('<details', false)
            ->assertSeeTextInOrder(['Campo', 'Antes', 'Después'])
            ->assertSeeTextInOrder(['Color principal', '#FF671F', '#C4470F'])
            ->assertSeeTextInOrder(['Nombre corto', 'Segurex', 'Segurex Co'])
            ->assertSeeTextInOrder(['campo_nuevo', 'a', 'b']);
    }

    // --- Filters -----------------------------------------------------------

    public function test_the_text_filter_matches_people_and_apps(): void
    {
        $this->launch($this->ana, $this->cartera);
        $this->launch($this->luis, $this->portafolio);

        $this->audit(['q' => 'ANA@'])->assertSeeText('Ana Pérez')->assertDontSeeText('Luis Mora');
        $this->audit(['q' => 'mora'])->assertSeeText('Luis Mora')->assertDontSeeText('Ana Pérez');
        $this->audit(['q' => 'portafolio'])->assertSeeText('Luis Mora')->assertDontSeeText('Ana Pérez');
        $this->audit(['q' => 'Visualizador'])->assertSeeText('Ana Pérez')->assertDontSeeText('Luis Mora');
        $this->audit(['q' => '%'])->assertSeeText('No hay eventos con estos filtros.');
    }

    public function test_the_type_filter(): void
    {
        $this->launch($this->luis, $this->portafolio);
        $this->changeCompany($this->admin, ['color_primary' => '#C4470F']);
        SecurityLog::reauthentication($this->ana, true, null);

        $this->audit(['tipo' => 'apps'])->assertSeeText('Portafolio de productos')->assertDontSeeText('Cambió la empresa')->assertDontSeeText('Confirmó');
        $this->audit(['tipo' => 'cambios'])->assertSeeText('Cambió la empresa')->assertDontSeeText('Portafolio de productos')->assertDontSeeText('Confirmó');
        $this->audit(['tipo' => 'seguridad'])->assertSeeText('Confirmó')->assertDontSeeText('Portafolio de productos')->assertDontSeeText('Cambió la empresa');
        $this->audit()->assertSee('name="tipo" value="api"', false)->assertDontSee('API <span', false);
    }

    public function test_api_status_changes_are_listed_under_the_api_type_with_the_system_name(): void
    {
        $monitor = Integration::factory()->create(['name' => 'Monitor de disponibilidad', 'abilities' => [Integration::STATUS_WRITE]]);
        app(StatusReporter::class)->report($this->cartera, $monitor, ['status' => 'down', 'message' => 'SAP no responde']);
        $this->changeCompany($this->admin, ['color_primary' => '#C4470F']);

        $this->audit(['tipo' => 'api'])
            ->assertSeeTextInOrder(['Monitor de disponibilidad', 'API', 'Reportó el estado de la app Visualizador de cartera'])
            ->assertSeeTextInOrder(['Estado reportado', '—', 'down'])
            ->assertSeeTextInOrder(['Mensaje reportado', '—', 'SAP no responde'])
            ->assertDontSeeText('Cambió la empresa');
        $this->audit(['tipo' => 'cambios'])->assertSeeText('Cambió la empresa')->assertDontSeeText('Reportó el estado');
        $this->audit()->assertSeeText('Reportó el estado')->assertSeeText('Cambió la empresa');
        $this->audit(['q' => 'monitor'])->assertSeeText('Reportó el estado')->assertDontSeeText('Cambió la empresa');
    }

    public function test_the_company_filter(): void
    {
        $this->launch($this->ana, $this->cartera);
        $this->launch($this->luis, $this->portafolio);
        $this->changeCompany($this->admin, ['color_primary' => '#C4470F']);

        $this->audit(['empresa' => 'derivados'])
            ->assertSeeText('Luis Mora')
            ->assertDontSeeText('Ana Pérez')
            ->assertDontSeeText('Cambió la empresa');

        $this->audit(['empresa' => 'segurex'])
            ->assertSeeText('Ana Pérez')
            ->assertSeeText('Cambió la empresa')
            ->assertDontSeeText('Luis Mora');
    }

    public function test_the_period_filter_uses_bogota_days(): void
    {
        $this->launch($this->ana, $this->cartera, at: CarbonImmutable::parse('2026-10-06 00:05', 'America/Bogota'));
        $this->launch($this->luis, $this->portafolio, at: CarbonImmutable::parse('2026-10-05 23:55', 'America/Bogota'));
        $old = User::factory()->microsoft($this->segurex)->create(['name' => 'Persona Antigua']);
        $this->launch($old, $this->cartera, at: CarbonImmutable::parse('2026-09-29 23:59', 'America/Bogota'));

        $this->audit(['periodo' => 'hoy'])->assertSeeText('Ana Pérez')->assertDontSeeText('Luis Mora');
        $this->audit()->assertSeeText('Luis Mora')->assertDontSeeText('Persona Antigua');
        $this->audit(['periodo' => '30'])->assertSeeText('Persona Antigua');
        $this->audit(['periodo' => 'rango', 'desde' => '2026-10-05', 'hasta' => '2026-10-05'])
            ->assertSeeText('Luis Mora')
            ->assertDontSeeText('Ana Pérez')
            ->assertDontSeeText('Persona Antigua');
    }

    public function test_odd_parameters_are_ignored(): void
    {
        $this->launch($this->ana, $this->cartera);

        $this->audit(['q' => ['x'], 'tipo' => 'otro', 'empresa' => 'nadie', 'periodo' => 'siempre', 'cursor' => 'basura'])
            ->assertOk()
            ->assertSeeText('Ana Pérez');
    }

    // --- Pagination -------------------------------------------------------

    public function test_pages_of_fifty_with_a_cursor_across_both_sources(): void
    {
        foreach (range(1, 60) as $i) {
            $at = CarbonImmutable::instance(now()->subMinutes($i));
            if ($i % 2 === 0) {
                $this->launch($this->ana, $this->cartera, at: $at);
            } else {
                SecurityLog::reauthentication($this->luis, true, null);
                DB::table('activity_log')->where('id', DB::table('activity_log')->max('id'))->update(['created_at' => $at]);
            }
        }
        // Same timestamp in both sources: order stays stable.
        $this->launch($this->ana, $this->cartera, at: CarbonImmutable::instance(now()->subMinutes(61)));
        SecurityLog::reauthentication($this->luis, true, null);
        DB::table('activity_log')->where('id', DB::table('activity_log')->max('id'))->update(['created_at' => now()->subMinutes(61)]);

        $first = app(AuditFeed::class)->page(AuditFilters::fromArray(['periodo' => '7']));
        $this->assertCount(AuditFeed::PER_PAGE, $first['entries']);
        $this->assertNotNull($first['next']);

        $second = app(AuditFeed::class)->page(AuditFilters::fromArray(['periodo' => '7']), $first['next']);
        $this->assertCount(12, $second['entries']);
        $this->assertNull($second['next']);

        $keys = collect([...$first['entries'], ...$second['entries']])->map(fn ($entry) => $entry->kind.$entry->id);
        $this->assertSame(62, $keys->unique()->count());

        $times = collect([...$first['entries'], ...$second['entries']])->map(fn ($entry) => $entry->occurredAt->getTimestamp())->all();
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);

        $this->audit()->assertSeeText('Mostrando 50 eventos')->assertSee('cursor=', false)->assertSeeText('Más antiguos');
        $this->audit(['cursor' => $first['next']])->assertSeeText('Mostrando 12 eventos')->assertSeeText('Más recientes');
    }

    public function test_the_query_count_does_not_grow_with_the_rows(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->audit()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->launch($this->ana, $this->cartera);
        $this->changeCompany($this->admin, ['color_primary' => '#000001']);
        SecurityLog::reauthentication($this->ana, true, null);
        $queries(); // Warms the permission cache.
        $few = $queries();

        foreach (range(2, 40) as $i) {
            $this->launch($i % 2 ? $this->ana : $this->luis, $i % 3 ? $this->cartera : $this->portafolio);
            $this->changeCompany($this->admin, ['color_primary' => sprintf('#%06X', $i)]);
            SecurityLog::reauthentication($i % 2 ? $this->ana : $this->luis, true, null);
        }

        $this->assertSame($few, $queries());
        $this->assertLessThanOrEqual(25, $few);
    }
}
