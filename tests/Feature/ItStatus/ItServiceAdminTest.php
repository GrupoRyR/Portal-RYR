<?php

namespace Tests\Feature\ItStatus;

use App\Livewire\Admin\ItServiceManager;
use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ItServiceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $segurex;

    private Company $sdc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex LATAM SAS', 'short_name' => 'Segurex']);
        $this->sdc = Company::factory()->create(['name' => 'Soluciones y Derivados SAS', 'short_name' => 'SDC']);
        $this->admin = tap(User::factory()->create())->assignRole('portal_admin');
    }

    // --- Access -----------------------------------------------------------

    public function test_admins_and_announcements_editors_reach_the_page(): void
    {
        $super = tap(User::factory()->create())->assignRole('super_admin');
        $homelessEditor = tap(User::factory()->create(['company_id' => null]))->assignRole('announcements_editor');
        $management = tap(User::factory()->create(['company_id' => $this->segurex->id]))->assignRole('management');

        $this->actingAs($this->admin)->get(route('admin.it-services.index'))->assertOk()->assertSee('Estado de TI');
        $this->actingAs($super)->get(route('admin.it-services.index'))->assertOk();
        $this->actingAs($this->editor())->get(route('admin.it-services.index'))->assertOk();
        $this->actingAs($homelessEditor)->get(route('admin.it-services.index'))->assertForbidden();
        $this->actingAs($management)->get(route('admin.it-services.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('admin.it-services.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.it-services.index'))->assertRedirect(route('login'));
    }

    public function test_the_component_refuses_people_without_the_role(): void
    {
        Livewire::actingAs(User::factory()->create(['company_id' => $this->segurex->id]))->test(ItServiceManager::class)->assertForbidden();
        Livewire::actingAs(tap(User::factory()->create(['company_id' => $this->segurex->id]))->assignRole('management'))->test(ItServiceManager::class)->assertForbidden();
        Livewire::actingAs(tap(User::factory()->create(['company_id' => null]))->assignRole('announcements_editor'))->test(ItServiceManager::class)->assertForbidden();
    }

    public function test_a_role_taken_away_stops_the_next_action(): void
    {
        $component = $this->manager()->call('newService')->set('name', 'Correo');

        $this->admin->removeRole('portal_admin');

        $component->call('saveService')->assertForbidden();
        $this->assertSame(0, ItService::count());
    }

    public function test_the_sidebar_links_to_the_page_only_for_those_who_may_open_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.it-services.index'))
            ->assertSee('href="'.route('admin.it-services.index').'" aria-current="page"', false);

        $this->actingAs($this->editor())->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.it-services.index').'"', false);

        $homeless = tap(User::factory()->create(['company_id' => null]))->assignRole('announcements_editor');
        $this->actingAs($homeless)->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.it-services.index').'"', false);
    }

    // --- Announcements editors: their own company only ------------------------

    public function test_an_editor_sees_and_manages_only_their_own_company(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'position' => 1]);
        ItService::factory()->for($this->sdc)->create(['name' => 'Impresoras']);

        $component = Livewire::actingAs($this->editor())->test(ItServiceManager::class)
            ->assertSet('companyId', $this->segurex->id)
            ->assertViewHas('companies', fn ($companies) => $companies->pluck('id')->all() === [$this->segurex->id])
            ->assertSee('Correo')
            ->assertDontSee('Impresoras');

        $component->call('newService')->set('name', 'Intranet')->set('status', ItService::STATUS_DOWN)->call('saveService')->assertHasNoErrors();
        $created = ItService::where('name', 'Intranet')->sole();
        $this->assertSame($this->segurex->id, $created->company_id);

        $component->call('editService', $created->id)->set('status', ItService::STATUS_OPERATIONAL)->call('saveService')->assertHasNoErrors();
        $this->assertSame(ItService::STATUS_OPERATIONAL, $created->fresh()->status);

        $component->call('moveService', $created->id, -1);
        $this->assertSame(['Intranet', 'Correo'], $this->names($this->segurex));

        $component->call('deleteService', $created->id);
        $this->assertNull($created->fresh());
    }

    public function test_an_editor_cannot_open_another_company_from_the_address(): void
    {
        Livewire::withQueryParams(['empresa' => $this->sdc->id])
            ->actingAs($this->editor())
            ->test(ItServiceManager::class)
            ->assertForbidden();

        $this->actingAs($this->editor())
            ->get(route('admin.it-services.index', ['empresa' => $this->sdc->id]))
            ->assertForbidden();

        // Their own company in the address is fine.
        Livewire::withQueryParams(['empresa' => $this->segurex->id])
            ->actingAs($this->editor())
            ->test(ItServiceManager::class)
            ->assertOk()
            ->assertSet('companyId', $this->segurex->id);
    }

    public function test_an_editor_cannot_switch_to_another_company(): void
    {
        Livewire::actingAs($this->editor())->test(ItServiceManager::class)
            ->set('companyId', $this->sdc->id)
            ->assertForbidden();
    }

    public function test_an_editor_cannot_touch_another_companys_services(): void
    {
        $theirs = ItService::factory()->for($this->sdc)->create(['name' => 'Impresoras', 'position' => 1]);
        ItService::factory()->for($this->sdc)->create(['position' => 2]);
        $editor = $this->editor();

        Livewire::actingAs($editor)->test(ItServiceManager::class)->call('editService', $theirs->id)->assertNotFound();
        Livewire::actingAs($editor)->test(ItServiceManager::class)->call('moveService', $theirs->id, 1)->assertNotFound();
        Livewire::actingAs($editor)->test(ItServiceManager::class)->call('deleteService', $theirs->id)->assertNotFound();

        $this->assertSame('Impresoras', $theirs->fresh()?->name);
        $this->assertSame(1, $theirs->fresh()->position);
    }

    public function test_an_editor_who_loses_the_role_is_refused_on_the_next_render(): void
    {
        $editor = $this->editor();
        $component = Livewire::actingAs($editor)->test(ItServiceManager::class)->assertOk();

        $editor->removeRole('announcements_editor');

        // cancelService checks nothing itself: the render refuses.
        $component->call('cancelService')->assertForbidden();
    }

    // --- Companies --------------------------------------------------------

    public function test_the_page_lists_only_the_selected_companys_services_in_order(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Intranet', 'position' => 2]);
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'position' => 1]);
        ItService::factory()->for($this->sdc)->create(['name' => 'Impresoras']);

        $this->manager($this->segurex)->assertSeeInOrder(['Correo', 'Intranet'])->assertDontSee('Impresoras');
        $this->manager($this->sdc)->assertSee('Impresoras')->assertDontSee('Intranet');
    }

    public function test_an_unknown_company_falls_back_to_the_first_one(): void
    {
        $this->manager(null)->set('companyId', 999999)->assertSet('companyId', $this->segurex->id);
    }

    public function test_a_service_of_another_company_is_not_found(): void
    {
        $theirs = ItService::factory()->for($this->sdc)->create(['name' => 'Impresoras', 'position' => 1]);

        $this->manager()->call('editService', $theirs->id)->assertNotFound();
        $this->manager()->call('moveService', $theirs->id, 1)->assertNotFound();
        $this->manager()->call('deleteService', $theirs->id)->assertNotFound();

        $this->assertNotNull($theirs->fresh());
    }

    // --- Create and edit ----------------------------------------------------

    public function test_a_service_is_created_at_the_end_of_the_companys_list(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'position' => 4]);

        $this->manager()->call('newService')
            ->assertSet('status', ItService::STATUS_OPERATIONAL)
            ->set('name', '  Internet   sede  norte ')
            ->set('status', ItService::STATUS_DEGRADED)
            ->set('message', '  Lento desde las 8 a. m. ')
            ->call('saveService')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $created = ItService::where('name', 'Internet sede norte')->sole();
        $this->assertSame($this->segurex->id, $created->company_id);
        $this->assertSame(ItService::STATUS_DEGRADED, $created->status);
        $this->assertSame('Lento desde las 8 a. m.', $created->message);
        $this->assertSame(5, $created->position);
    }

    public function test_an_empty_message_is_stored_as_null(): void
    {
        $this->manager()->call('newService')->set('name', 'VPN')->set('message', '   ')->call('saveService')->assertHasNoErrors();

        $this->assertNull(ItService::sole()->message);
    }

    public function test_the_fields_are_validated(): void
    {
        $save = fn (array $fields) => tap($this->manager()->call('newService'), function (Testable $component) use ($fields) {
            foreach ($fields as $field => $value) {
                $component->set($field, $value);
            }
        })->call('saveService');

        $save(['name' => '   '])->assertHasErrors('name');
        $save(['name' => str_repeat('a', ItService::NAME_MAX + 1)])->assertHasErrors('name');
        $save(['name' => 'VPN', 'status' => 'broken'])->assertHasErrors('status');
        $save(['name' => 'VPN', 'message' => str_repeat('a', ItService::MESSAGE_MAX + 1)])->assertHasErrors('message');

        $this->assertSame(0, ItService::count());

        $save(['name' => str_repeat('a', ItService::NAME_MAX), 'message' => str_repeat('b', ItService::MESSAGE_MAX)])->assertHasNoErrors();
        $this->assertSame(1, ItService::count());
    }

    public function test_names_are_unique_per_company_ignoring_case_and_accents(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Telefonía IP']);
        ItService::factory()->for($this->sdc)->create(['name' => 'Impresoras']);

        $this->manager()->call('newService')->set('name', 'telefonia ip')->call('saveService')->assertHasErrors('name');
        $this->manager()->call('newService')->set('name', 'TELEFONÍA IP')->call('saveService')->assertHasErrors('name');

        // The same name in another company is fine.
        $this->manager()->call('newService')->set('name', 'Impresoras')->call('saveService')->assertHasNoErrors();
        $this->assertSame(1, ItService::where('company_id', $this->segurex->id)->where('name', 'Impresoras')->count());
    }

    public function test_a_service_is_edited_and_may_keep_its_own_name(): void
    {
        $service = ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'status' => 'operational', 'message' => null]);

        $this->manager()->call('editService', $service->id)
            ->assertSet('name', 'Correo')
            ->assertSet('status', 'operational')
            ->set('name', 'correo')
            ->set('status', ItService::STATUS_DOWN)
            ->set('message', 'Microsoft 365 no responde.')
            ->call('saveService')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $service->refresh();
        $this->assertSame('correo', $service->name);
        $this->assertSame(ItService::STATUS_DOWN, $service->status);
        $this->assertSame('Microsoft 365 no responde.', $service->message);
    }

    public function test_a_name_taken_at_the_same_moment_ends_as_a_form_error(): void
    {
        // MariaDB's collation, or a second admin saving at once: the row
        // appears between the check and the insert, so the index decides.
        $company = $this->segurex;
        $raced = false;
        ItService::creating(function (ItService $service) use (&$raced, $company) {
            if (! $raced) {
                $raced = true;
                DB::table('it_services')->insert([
                    'company_id' => $company->id, 'name' => $service->name, 'status' => 'operational', 'position' => 9,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->manager()->call('newService')->set('name', 'Correo')->call('saveService')->assertHasErrors('name');

        $this->assertSame(1, ItService::where('name', 'Correo')->count());
    }

    // --- Order and delete ---------------------------------------------------

    public function test_services_move_up_and_down_and_keep_a_clean_numbering(): void
    {
        $a = ItService::factory()->for($this->segurex)->create(['name' => 'A', 'position' => 10]);
        ItService::factory()->for($this->segurex)->create(['name' => 'B', 'position' => 20]);
        $c = ItService::factory()->for($this->segurex)->create(['name' => 'C', 'position' => 30]);
        $other = ItService::factory()->for($this->sdc)->create(['name' => 'Z', 'position' => 7]);

        $this->manager()->call('moveService', $c->id, -1);
        $this->assertSame(['A', 'C', 'B'], $this->names($this->segurex));
        $this->assertSame([1, 2, 3], ItService::where('company_id', $this->segurex->id)->ordered()->pluck('position')->all());

        $this->manager()->call('moveService', $a->id, -1);
        $this->assertSame(['A', 'C', 'B'], $this->names($this->segurex));

        $this->manager()->call('moveService', $a->id, 1);
        $this->assertSame(['C', 'A', 'B'], $this->names($this->segurex));
        $this->assertSame(7, $other->fresh()->position);
    }

    public function test_a_service_is_deleted(): void
    {
        $service = ItService::factory()->for($this->segurex)->create(['name' => 'VPN']);

        $this->manager()->call('editService', $service->id)
            ->call('deleteService', $service->id)
            ->assertSet('showForm', false)
            ->assertSet('notice', 'Se borró «VPN».');

        $this->assertNull($service->fresh());
    }

    public function test_the_list_shows_each_status_and_message(): void
    {
        ItService::factory()->for($this->segurex)->create(['name' => 'Correo', 'status' => 'operational', 'position' => 1]);
        ItService::factory()->for($this->segurex)->create(['name' => 'VPN', 'status' => 'degraded', 'message' => 'Lenta en la sede norte', 'position' => 2]);
        ItService::factory()->for($this->segurex)->create(['name' => 'Telefonía', 'status' => 'down', 'position' => 3]);

        $this->manager()->assertSeeInOrder(['Correo', 'Operativo', 'VPN', 'Lenta en la sede norte', 'Con fallas', 'Telefonía', 'Fuera de servicio']);
    }

    // --- Helpers ------------------------------------------------------------

    private function editor(): User
    {
        return tap(User::factory()->create(['company_id' => $this->segurex->id]))->assignRole('announcements_editor');
    }

    private function manager(?Company $company = null): Testable
    {
        $component = Livewire::actingAs($this->admin)->test(ItServiceManager::class);

        return $company === null ? $component : $component->set('companyId', $company->id);
    }

    /**
     * @return list<string>
     */
    private function names(Company $company): array
    {
        return ItService::where('company_id', $company->id)->ordered()->pluck('name')->all();
    }
}
