<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\EventEditor;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin «Eventos» editor (odd/tasks/inicio-fase-2.md WU2): the same people
 * and company scoping as announcements (App\Policies\EventPolicy),
 * re-authorized on mount and on every action.
 */
class EventEditorTest extends TestCase
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

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create();
        $this->editor->assignRole('announcements_editor');
    }

    // --- Pages ------------------------------------------------------------

    public function test_the_editor_pages_follow_the_policy(): void
    {
        $mine = Event::factory()->forCompanies($this->segurex)->create(['title' => 'De Segurex']);
        $all = Event::factory()->create(['title' => 'Para todas']);
        $theirs = Event::factory()->forCompanies($this->derivados)->create(['title' => 'De Derivados']);

        $this->actingAs($this->admin)->get(route('admin.events.create'))->assertOk()->assertSeeText('Nuevo evento');
        $this->actingAs($this->admin)->get(route('admin.events.edit', $all))->assertOk()->assertSeeText('Editar evento');

        $this->actingAs($this->editor)->get(route('admin.events.create'))->assertOk();
        $this->actingAs($this->editor)->get(route('admin.events.edit', $mine))->assertOk()->assertSee('value="De Segurex"', false);
        $this->actingAs($this->editor)->get(route('admin.events.edit', $all))->assertForbidden();
        $this->actingAs($this->editor)->get(route('admin.events.edit', $theirs))->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/eventos/999')->assertNotFound();

        $nobody = User::factory()->microsoft($this->segurex)->create();
        $this->actingAs($nobody)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.events.create'))->assertForbidden();
        $this->actingAs($nobody)->get(route('admin.events.edit', $mine))->assertForbidden();
    }

    // --- Create and edit --------------------------------------------------

    public function test_event_is_bound_only_by_its_own_routes(): void
    {
        // No global Route::model('event'): another {event} parameter (a
        // webhook, an API route) must not turn into an App\Models\Event.
        $this->assertNull(app('router')->getBindingCallback('event'));
    }

    public function test_an_admin_creates_a_timed_event_for_every_company(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->assertSet('audience', Event::AUDIENCE_ALL)
            ->assertSet('publishedAt', '2026-10-29T10:00')
            ->set('title', '  Reunión general  ')
            ->set('description', "Orden del día:\ncierre de mes")
            ->set('location', 'Sala 2')
            ->set('startsAt', '2026-11-05T15:00')
            ->set('endsAt', '2026-11-05T17:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.events.edit', Event::query()->sole()));

        $event = Event::query()->sole();
        $this->assertSame('Reunión general', $event->title);
        $this->assertSame("Orden del día:\ncierre de mes", $event->description);
        $this->assertSame('Sala 2', $event->location);
        $this->assertFalse($event->all_day);
        $this->assertSame('2026-11-05 15:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 17:00:00', $event->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-29 10:00:00', $event->published_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $event->created_by);
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);
    }

    public function test_an_all_day_event_keeps_its_days(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Vacaciones colectivas')
            ->set('allDay', true)
            ->set('startsAt', '2026-12-24')
            ->set('endsAt', '2026-12-26')
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::query()->sole();
        $this->assertTrue($event->all_day);
        $this->assertSame('2026-12-24 00:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-26 00:00:00', $event->ends_at->format('Y-m-d H:i:s'));

        // One day: the same last day as the first one is no end at all.
        $this->editorFor($event, $this->admin)
            ->assertSet('startsAt', '2026-12-24')
            ->assertSet('endsAt', '2026-12-26')
            ->set('endsAt', '2026-12-24')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($event->fresh()->ends_at);
    }

    public function test_switching_all_day_converts_the_dates(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('startsAt', '2026-11-05T15:00')
            ->set('endsAt', '2026-11-05T17:00')
            ->set('allDay', true)
            ->assertSet('startsAt', '2026-11-05')
            ->assertSet('endsAt', '')
            ->assertSee('type="date"', false)
            ->set('allDay', false)
            ->assertSet('startsAt', '2026-11-05T09:00')
            ->assertSet('endsAt', '')
            ->assertSee('type="datetime-local"', false);
    }

    public function test_editing_keeps_the_creator_and_updates_the_fields(): void
    {
        $event = Event::factory()->forCompanies($this->segurex)->create(['created_by' => $this->editor->id, 'title' => 'Antes']);

        $this->editorFor($event, $this->admin)
            ->assertSet('audience', Event::AUDIENCE_COMPANIES)
            ->assertSet('companyIds', [(string) $this->segurex->id])
            ->set('title', 'Después')
            ->set('companyIds', [(string) $this->segurex->id, (string) $this->derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $event->refresh();
        $this->assertSame('Después', $event->title);
        $this->assertSame($this->editor->id, $event->created_by);
        $this->assertEqualsCanonicalizing([$this->segurex->id, $this->derivados->id], $event->companies()->pluck('companies.id')->all());
    }

    public function test_an_all_day_event_can_repeat_every_year(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->assertDontSeeText('Se repite cada año')
            ->set('title', 'Aniversario de Segurex')
            ->set('allDay', true)
            ->assertSeeText('Se repite cada año')
            ->set('startsAt', '2026-03-15')
            ->set('repeatsYearly', true)
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::query()->sole();
        $this->assertTrue($event->repeats_yearly);
        $this->assertTrue($event->repeatsYearly());
        // Its first date passed this year: it is still published, for next year.
        $this->assertSame(Event::STATUS_PUBLISHED, $event->status);

        $this->editorFor($event, $this->admin)
            ->assertSet('repeatsYearly', true)
            ->set('repeatsYearly', false)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertFalse($event->fresh()->repeats_yearly);
    }

    public function test_a_yearly_event_lasts_less_than_a_year(): void
    {
        $editor = Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Temporada de cierre')
            ->set('allDay', true)
            ->set('repeatsYearly', true)
            ->set('startsAt', '2026-03-15')
            ->set('endsAt', '2027-03-15')
            ->call('save')
            ->assertHasErrors(['endsAt' => 'before'])
            ->assertSeeText('Una fecha que se repite cada año debe durar menos de un año.');
        $this->assertSame(0, Event::query()->count());

        $editor->set('endsAt', '2027-03-14')->call('save')->assertHasNoErrors();
        $this->assertSame('2027-03-14', Event::query()->sole()->ends_at->toDateString());

        // A one-time event may last longer.
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Proyecto largo')
            ->set('allDay', true)
            ->set('startsAt', '2026-03-15')
            ->set('endsAt', '2027-06-15')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_only_an_all_day_event_can_repeat_every_year(): void
    {
        // Unticking «Todo el día» clears it.
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('allDay', true)
            ->set('repeatsYearly', true)
            ->set('allDay', false)
            ->assertSet('repeatsYearly', false);

        // A timed event sent with it anyway is refused.
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Reunión general')
            ->set('startsAt', '2026-11-05T15:00')
            ->set('repeatsYearly', true)
            ->call('save')
            ->assertHasErrors(['repeatsYearly' => 'declined'])
            ->assertSeeText('Solo un evento de todo el día puede repetirse cada año.');

        $this->assertSame(0, Event::query()->count());
    }

    // --- Validation -------------------------------------------------------

    public function test_the_fields_are_required_and_limited(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('publishedAt', '')
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'startsAt' => 'required', 'publishedAt' => 'required'])
            ->set('title', str_repeat('a', Event::TITLE_MAX + 1))
            ->set('description', str_repeat('a', Event::DESCRIPTION_MAX + 1))
            ->set('location', str_repeat('a', Event::LOCATION_MAX + 1))
            ->set('startsAt', '5 de noviembre')
            ->call('save')
            ->assertHasErrors(['title' => 'max', 'description' => 'max', 'location' => 'max', 'startsAt' => 'date_format'])
            ->assertSeeText('Usa como máximo 500 caracteres.');

        $this->assertSame(0, Event::query()->count());
    }

    public function test_the_end_must_come_after_the_start(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Reunión')
            ->set('startsAt', '2026-11-05T15:00')
            ->set('endsAt', '2026-11-05T15:00')
            ->call('save')
            ->assertHasErrors(['endsAt' => 'after'])
            ->assertSeeText('«Termina» debe ser posterior a «Empieza».')
            ->set('allDay', true)
            ->set('startsAt', '2026-11-05')
            ->set('endsAt', '2026-11-04')
            ->call('save')
            ->assertHasErrors(['endsAt' => 'after_or_equal'])
            ->set('endsAt', '2026-11-05T17:00')
            ->call('save')
            ->assertHasErrors(['endsAt' => 'date_format']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_specific_companies_need_at_least_one_existing_company(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Reunión')
            ->set('startsAt', '2026-11-05T15:00')
            ->set('audience', Event::AUDIENCE_COMPANIES)
            ->call('save')
            ->assertHasErrors(['companyIds' => 'required'])
            ->set('companyIds', ['999'])
            ->call('save')
            ->assertHasErrors(['companyIds.0' => 'exists'])
            ->set('audience', 'nadie')
            ->call('save')
            ->assertHasErrors(['audience' => 'in']);

        $this->assertSame(0, Event::query()->count());
    }

    // --- Company scoping --------------------------------------------------

    public function test_an_editor_creates_events_for_their_own_company_only(): void
    {
        Livewire::actingAs($this->editor)->test(EventEditor::class)
            ->assertSet('audience', Event::AUDIENCE_COMPANIES)
            ->assertSet('companyIds', [(string) $this->segurex->id])
            ->assertSeeText('Segurex')
            ->assertDontSeeText('Derivados')
            ->set('title', 'Para Segurex')
            ->set('startsAt', '2026-11-05T15:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->segurex->id], Event::query()->sole()->companies()->pluck('companies.id')->all());
    }

    public function test_an_editor_cannot_publish_to_every_company_or_another_company(): void
    {
        foreach ([[Event::AUDIENCE_ALL, []], [Event::AUDIENCE_COMPANIES, [(string) $this->derivados->id]], [Event::AUDIENCE_COMPANIES, [(string) $this->segurex->id, (string) $this->derivados->id]]] as [$audience, $ids]) {
            Livewire::actingAs($this->editor)->test(EventEditor::class)
                ->set('title', 'Intento')
                ->set('startsAt', '2026-11-05T15:00')
                ->set('audience', $audience)
                ->set('companyIds', $ids)
                ->call('save')
                ->assertForbidden();
        }

        $this->assertSame(0, Event::query()->count());
    }

    public function test_an_editor_cannot_open_another_company_event(): void
    {
        $theirs = Event::factory()->forCompanies($this->derivados)->create();
        $all = Event::factory()->create();

        Livewire::actingAs($this->editor)->test(EventEditor::class, ['event' => $theirs])->assertForbidden();
        Livewire::actingAs($this->editor)->test(EventEditor::class, ['event' => $all])->assertForbidden();
    }

    // --- Withdraw and republish ---------------------------------------------

    public function test_withdrawing_and_republishing(): void
    {
        $event = Event::factory()->forCompanies($this->segurex)->create();

        $this->editorFor($event, $this->editor)
            ->assertSeeText('Publicado')
            ->assertSee('wire:confirm', false)
            ->call('withdraw')
            ->assertSeeText('Retirado')
            ->assertSeeText('Volver a publicar');

        $this->assertSame(Event::STATUS_WITHDRAWN, $event->fresh()->status);

        $this->editorFor($event->fresh(), $this->editor)->call('republish');

        $this->assertSame(Event::STATUS_PUBLISHED, $event->fresh()->status);
    }

    public function test_every_action_checks_the_role_again(): void
    {
        $event = Event::factory()->create(['title' => 'Antes']);
        $withdrawing = $this->editorFor($event, $this->admin);
        $saving = $this->editorFor($event, $this->admin)->set('title', 'Después');

        $this->admin->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $withdrawing->call('withdraw')->assertForbidden();
        $saving->call('save')->assertForbidden();
        $this->assertNull($event->fresh()->withdrawn_at);
        $this->assertSame('Antes', $event->fresh()->title);
    }

    public function test_changes_are_recorded_with_their_causer(): void
    {
        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', 'Simulacro')
            ->set('startsAt', '2026-11-05T15:00')
            ->call('save');

        $created = Activity::query()->where('log_name', Event::AUDIT_LOG)->where('event', 'created')->sole();
        $this->assertSame($this->admin->id, $created->causer_id);
        $this->assertSame('Simulacro', $created->properties['attributes']['title'] ?? null);
    }

    private function editorFor(Event $event, User $user): Testable
    {
        return Livewire::actingAs($user)->test(EventEditor::class, ['event' => $event]);
    }
}
