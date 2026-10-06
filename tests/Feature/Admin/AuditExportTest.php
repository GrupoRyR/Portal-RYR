<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditCsv;
use App\Models\App;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $admin;

    private App $cartera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        activity()->withoutLogs(function () {
            $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'slug' => 'segurex']);
            $this->cartera = App::factory()->forCompanies($this->segurex)->create(['name' => 'Visualizador de cartera', 'slug' => 'cartera']);
        });

        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');
    }

    private function launch(User $user, string $device = 'desktop', ?CarbonImmutable $at = null): void
    {
        AppLaunch::create([
            'user_id' => $user->id,
            'app_id' => $this->cartera->id,
            'company_id' => $user->company_id,
            'device' => $device,
            'launched_at' => $at ?? now(),
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $csv): array
    {
        $lines = preg_split('/\r?\n/', rtrim(substr($csv, 3), "\r\n"));

        return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
    }

    public function test_the_export_is_utf8_with_bom_and_a_header_row(): void
    {
        $person = User::factory()->microsoft($this->segurex)->create(['name' => 'María Ñúñez', 'email' => 'maria@segurex.com']);
        $this->launch($person, 'phone', CarbonImmutable::parse('2026-10-06 08:15', 'America/Bogota'));

        $response = $this->actingAs($this->admin)->get(route('admin.audit.export'));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=auditoria-2026-10-06.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $rows = $this->rows($csv);
        $this->assertSame(['Fecha', 'Persona', 'Correo', 'Tipo', 'Qué pasó', 'Empresa', 'Dispositivo'], $rows[0]);
        $this->assertSame(['2026-10-06 08:15', 'María Ñúñez', 'maria@segurex.com', 'Abrió app', 'Visualizador de cartera', 'Segurex', 'Celular'], $rows[1]);
    }

    public function test_the_export_follows_the_filters(): void
    {
        $ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez']);
        $luis = User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Mora']);
        $this->launch($ana);
        $this->launch($luis);

        $csv = $this->actingAs($this->admin)->get(route('admin.audit.export', ['q' => 'luis']))->streamedContent();

        $this->assertStringContainsString('Luis Mora', $csv);
        $this->assertStringNotContainsString('Ana Pérez', $csv);
    }

    public function test_quotes_and_separators_are_escaped(): void
    {
        $person = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana "La Jefa"; Pérez', 'email' => 'ana@segurex.com']);
        $this->launch($person);

        $csv = $this->actingAs($this->admin)->get(route('admin.audit.export'))->streamedContent();

        $this->assertStringContainsString('"Ana ""La Jefa""; Pérez"', $csv);
        $this->assertSame('Ana "La Jefa"; Pérez', $this->rows($csv)[1][1]);
    }

    public function test_formula_like_cells_are_neutralized(): void
    {
        foreach (['=HYPERLINK("http://x")', '+57 300', '-1+1', '@SUM(A1)'] as $i => $name) {
            $person = User::factory()->microsoft($this->segurex)->create(['name' => $name, 'email' => "p{$i}@segurex.com"]);
            $this->launch($person, at: CarbonImmutable::instance(now()->subMinutes($i)));
        }

        $rows = $this->rows($this->actingAs($this->admin)->get(route('admin.audit.export'))->streamedContent());

        $this->assertSame(["'=HYPERLINK(\"http://x\")", "'+57 300", "'-1+1", "'@SUM(A1)"], array_column(array_slice($rows, 1), 1));
    }

    public function test_the_export_is_capped_with_a_visible_note(): void
    {
        $this->app->instance(AuditCsv::class, new AuditCsv(maxRows: 3));
        foreach (range(1, 5) as $i) {
            $this->launch($this->admin, at: CarbonImmutable::instance(now()->subMinutes($i)));
        }

        $rows = $this->rows($this->actingAs($this->admin)->get(route('admin.audit.export'))->streamedContent());

        $this->assertCount(5, $rows);
        $this->assertStringContainsString('solo los primeros 3 eventos', $rows[4][0]);

        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertSeeText('Exportar CSV')
            ->assertSeeText('máximo 50 000 eventos');
    }
}
