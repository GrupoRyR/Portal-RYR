<?php

namespace Tests\Feature\ItStatus;

use App\Audit\AuditEntry;
use App\Audit\AuditPresenter;
use App\Livewire\Admin\ItServiceManager;
use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría rows of Estado de TI: a readable subject, Spanish verbs, field
 * labels and status names, the company by name, one entry per reorder.
 */
class ItServiceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_service_is_named_and_its_fields_are_labelled(): void
    {
        $company = Company::factory()->create(['short_name' => 'Segurex']);
        $service = ItService::factory()->for($company)->create(['name' => 'Correo', 'status' => 'operational', 'position' => 1]);

        $created = $this->present($this->latest());
        $this->assertSame('Creó el servicio de TI «Correo»', $created->what);
        $changes = collect($created->changes)->keyBy('label');
        foreach (['Nombre', 'Estado', 'Mensaje', 'Posición', 'Empresa'] as $label) {
            $this->assertTrue($changes->has($label), "{$label} is missing.");
        }
        $this->assertSame('Segurex', $changes['Empresa']['new']);
        $this->assertSame('Operativo', $changes['Estado']['new']);

        $service->update(['status' => 'down', 'message' => 'Microsoft 365 no responde.']);
        $updated = $this->present($this->latest());
        $this->assertSame('Cambió el servicio de TI «Correo»', $updated->what);
        $this->assertSame([
            ['label' => 'Estado', 'old' => 'Operativo', 'new' => 'Fuera de servicio'],
            ['label' => 'Mensaje', 'old' => '—', 'new' => 'Microsoft 365 no responde.'],
        ], $updated->changes);
    }

    public function test_a_deleted_service_keeps_its_name(): void
    {
        $service = ItService::factory()->create(['name' => 'VPN']);
        $service->delete();

        $this->assertSame('Borró el servicio de TI «VPN»', $this->present($this->latest())->what);
    }

    public function test_the_admin_page_writes_one_entry_per_change_with_its_author(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = tap(User::factory()->create(['name' => 'Ana Pérez']), fn (User $user) => $user->assignRole('portal_admin'));
        $company = Company::factory()->create();
        ItService::factory()->for($company)->create(['position' => 1]);
        $second = ItService::factory()->for($company)->create(['position' => 2, 'name' => 'VPN']);
        Activity::query()->delete();

        $manager = Livewire::actingAs($admin)->test(ItServiceManager::class)->set('companyId', $company->id);

        $manager->call('moveService', $second->id, -1);
        $moved = $this->present($this->latest());
        $this->assertSame('Movió el servicio de TI «VPN»', $moved->what);
        $this->assertSame([['label' => 'Posición', 'old' => '2', 'new' => '1']], $moved->changes);
        $this->assertSame('Ana Pérez', $moved->who);
        $this->assertSame(1, Activity::count(), 'A reorder writes a single entry.');

        $manager->call('editService', $second->id)->set('status', 'degraded')->call('saveService');
        $this->assertSame([['label' => 'Estado', 'old' => 'Operativo', 'new' => 'Con fallas']], $this->present($this->latest())->changes);

        $manager->call('deleteService', $second->id);
        $this->assertSame('Borró el servicio de TI «VPN»', $this->present($this->latest())->what);
        $this->assertSame(3, Activity::count());
    }

    private function latest(): Activity
    {
        return Activity::query()->where('log_name', ItService::AUDIT_LOG)->latest('id')->firstOrFail();
    }

    private function present(Activity $activity): AuditEntry
    {
        return app(AuditPresenter::class)->activity($activity);
    }
}
