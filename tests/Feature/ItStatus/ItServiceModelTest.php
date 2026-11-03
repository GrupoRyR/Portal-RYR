<?php

namespace Tests\Feature\ItStatus;

use App\Models\App;
use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItServiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_name_is_unique_within_a_company_but_not_across_companies(): void
    {
        $segurex = Company::factory()->create();
        $sdc = Company::factory()->create();

        ItService::factory()->for($segurex)->create(['name' => 'Correo']);
        ItService::factory()->for($sdc)->create(['name' => 'Correo']);

        $this->expectException(QueryException::class);

        ItService::factory()->for($segurex)->create(['name' => 'Correo']);
    }

    public function test_a_new_service_is_operational_and_belongs_to_its_company(): void
    {
        $company = Company::factory()->create();
        $service = ItService::create(['company_id' => $company->id, 'name' => 'Internet', 'position' => 1])->fresh();

        $this->assertSame(ItService::STATUS_OPERATIONAL, $service->status);
        $this->assertTrue($service->isOperational());
        $this->assertTrue($service->company->is($company));
        $this->assertSame(1, $service->position);
    }

    public function test_the_statuses_have_spanish_labels(): void
    {
        $this->assertSame(['operational', 'degraded', 'down'], ItService::STATUSES);
        $this->assertSame('Operativo', ItService::statusLabel('operational'));
        $this->assertSame('Con fallas', ItService::statusLabel('degraded'));
        $this->assertSame('Fuera de servicio', ItService::statusLabel('down'));
        $this->assertSame('otro', ItService::statusLabel('otro'));
    }

    public function test_deleting_a_company_deletes_its_services(): void
    {
        $service = ItService::factory()->create();

        $service->company->delete();

        $this->assertSame(0, ItService::count());
    }

    public function test_index_and_key_names_fit_in_mysql(): void
    {
        $names = [
            ...array_column(Schema::getIndexes('it_services'), 'name'),
            ...array_filter(array_column(Schema::getForeignKeys('it_services'), 'name')),
        ];

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} is longer than 64 characters.");
        }

        $this->assertContains('it_svc_company_name_uq', $names);
        $this->assertContains('it_svc_company_pos_ix', $names);
    }

    public function test_the_policy_lets_in_exactly_the_people_who_manage_apps(): void
    {
        $this->seed(RoleSeeder::class);
        $service = ItService::factory()->create();

        foreach (['super_admin', 'portal_admin', 'announcements_editor', 'management', null] as $role) {
            $user = User::factory()->create();
            if ($role !== null) {
                $user->assignRole($role);
            }

            $managesApps = Gate::forUser($user)->allows('viewAny', App::class);

            foreach (['viewAny', 'create'] as $ability) {
                $this->assertSame($managesApps, Gate::forUser($user)->allows($ability, ItService::class), "{$ability} for ".($role ?? 'nobody'));
            }
            foreach (['update', 'delete'] as $ability) {
                $this->assertSame($managesApps, Gate::forUser($user)->allows($ability, $service), "{$ability} for ".($role ?? 'nobody'));
            }
        }
    }
}
