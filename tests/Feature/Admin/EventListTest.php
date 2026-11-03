<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\EventList;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin «Eventos» list: status tabs with counts; announcements editors only
 * ever see the events of their own company.
 */
class EventListTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create();
        $this->editor->assignRole('announcements_editor');
    }

    public function test_admins_see_every_event_and_editors_only_their_companys(): void
    {
        Event::factory()->create(['title' => 'Para todas']);
        Event::factory()->forCompanies($this->segurex)->create(['title' => 'De Segurex']);
        Event::factory()->forCompanies($this->derivados)->create(['title' => 'De Derivados']);
        Event::factory()->forCompanies($this->segurex, $this->derivados)->create(['title' => 'De las dos']);

        $this->actingAs($this->admin)->get(route('admin.events.index'))
            ->assertOk()
            ->assertSeeText(['Para todas', 'De Segurex', 'De Derivados', 'De las dos'])
            ->assertSee('href="'.route('admin.events.create').'"', false);

        $this->actingAs($this->editor)->get(route('admin.events.index'))
            ->assertOk()
            ->assertSeeText('De Segurex')
            ->assertDontSeeText('Para todas')
            ->assertDontSeeText('De Derivados')
            ->assertDontSeeText('De las dos');
    }

    public function test_the_tabs_count_and_filter_by_status(): void
    {
        Event::factory()->count(2)->create(['title' => 'Próximo']);
        Event::factory()->scheduled()->create(['title' => 'Programado']);
        Event::factory()->past()->create(['title' => 'Pasado']);
        Event::factory()->withdrawn()->create(['title' => 'Retirado']);

        Livewire::actingAs($this->admin)->test(EventList::class)
            ->assertSeeText(['Todos · 5', 'Publicados · 2', 'Programados · 1', 'Pasados · 1', 'Retirados · 1'])
            ->set('status', Event::STATUS_PAST)
            ->assertSeeText('Pasado')
            ->assertDontSeeText('Programado ·')
            ->assertDontSee('>Próximo<', false);
    }

    public function test_yearly_events_are_marked_and_never_past(): void
    {
        Event::factory()->allDay('2020-03-15')->yearly()->create(['title' => 'Aniversario', 'published_at' => '2020-01-01 08:00:00']);
        Event::factory()->allDay('2026-11-16')->create(['title' => 'Jornada de salud']);

        Livewire::actingAs($this->admin)->test(EventList::class)
            ->assertSeeText('Lunes 15 de marzo de 2027 · Todo el día · Cada año · Todas las empresas')
            ->assertSeeText('Lunes 16 de noviembre · Todo el día · Todas las empresas')
            ->assertSeeText(['Publicados · 2', 'Pasados · 0']);
    }

    public function test_people_without_the_role_cannot_list(): void
    {
        $nobody = User::factory()->microsoft($this->segurex)->create();

        $this->actingAs($nobody)->get(route('admin.events.index'))->assertForbidden();
        Livewire::actingAs($nobody)->test(EventList::class)->assertForbidden();

        $component = Livewire::actingAs($this->editor)->test(EventList::class);
        $this->editor->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $component->set('status', Event::STATUS_PAST)->assertForbidden();
    }

    public function test_editors_find_events_in_the_sidebar(): void
    {
        $this->actingAs($this->editor)->get(route('admin.announcements.index'))
            ->assertSee('href="'.route('admin.events.index').'"', false);

        $this->actingAs($this->admin)->get(route('admin.events.index'))
            ->assertSee('href="'.route('admin.announcements.index').'"', false)
            ->assertSee('href="'.route('admin.events.index').'"', false);
    }
}
