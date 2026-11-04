<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use App\Settings\PortalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Home\Concerns\ReadsTheHome;
use Tests\TestCase;

/**
 * «Estado de TI» in «Mi día» (odd/tasks/inicio-bloques.md H4,
 * App\ItStatus\ItStatusBoard): is anything broken for my company now, and
 * «Reportar un problema».
 */
class HomeItStatusTest extends TestCase
{
    use ReadsTheHome;
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->user = User::factory()->microsoft($this->company)->create();
        $this->openableApp(['name' => 'Cartera']);
    }

    public function test_all_good_says_so_and_offers_to_report_a_problem(): void
    {
        app(PortalSettings::class)->update([PortalSettings::SUPPORT_URL => 'https://soporte.segurex.com.co/tickets/nuevo'], null);

        $response = $this->home();
        $block = $this->element($response, 'id', 'estado-ti');

        $this->assertStringContainsString('Estado de TI', $block);
        $this->assertStringContainsString('Todo funciona con normalidad', $block);
        $this->assertStringContainsString('Reportar un problema', $block);
        $this->assertStringContainsString('href="'.route('support').'"', $block);
        $this->assertContains('#estado-ti', $this->sectionLinks($response));
    }

    public function test_without_a_support_url_there_is_no_report_link(): void
    {
        $block = $this->element($this->home(), 'id', 'estado-ti');

        $this->assertStringContainsString('Todo funciona con normalidad', $block);
        $this->assertStringNotContainsString('Reportar un problema', $block);
    }

    public function test_failing_services_of_the_company_and_apps_in_maintenance_are_listed(): void
    {
        ItService::factory()->down('Sin servicio desde las 8:00')->create(['company_id' => $this->company->id, 'name' => 'Correo']);
        ItService::factory()->degraded()->create(['company_id' => $this->company->id, 'name' => 'VPN']);
        ItService::factory()->create(['company_id' => $this->company->id, 'name' => 'Internet']);
        ItService::factory()->down()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Telefonía ajena']);
        $this->openableApp(['name' => 'Nómina', 'status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Actualización de versión', 'maintenance_until' => CarbonImmutable::parse('2026-11-04 18:00', 'America/Bogota')]);

        $block = $this->element($this->home(), 'id', 'estado-ti');

        $this->assertStringContainsString('Hay servicios fuera de servicio', $block);
        $this->assertStringContainsString('Correo', $block);
        $this->assertStringContainsString('Sin servicio desde las 8:00', $block);
        $this->assertStringContainsString('Fuera de servicio', $block);
        $this->assertStringContainsString('VPN', $block);
        $this->assertStringContainsString('Con fallas', $block);
        $this->assertStringContainsString('Nómina', $block);
        $this->assertStringContainsString('En mantenimiento', $block);
        $this->assertStringContainsString('Actualización de versión', $block);
        $this->assertStringNotContainsString('Internet', $block);
        $this->assertStringNotContainsString('Telefonía ajena', $block);
        $this->assertTrue(strpos($block, 'Correo') < strpos($block, 'VPN'), 'The worst comes first.');
    }

    public function test_an_account_without_an_active_company_sees_no_block(): void
    {
        $this->company->forceFill(['is_active' => false])->save();
        $this->assertNoElement($this->home(), 'id', 'estado-ti');

        $emergency = User::factory()->create(['is_emergency' => true, 'company_id' => null]);
        $this->assertNoElement($this->home(as: $emergency), 'id', 'estado-ti');
    }
}
