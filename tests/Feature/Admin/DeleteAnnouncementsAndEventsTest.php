<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\AnnouncementEditor;
use App\Livewire\Admin\EventEditor;
use App\Models\Announcement;
use App\Models\AnnouncementListEmail;
use App\Models\AnnouncementRead;
use App\Models\AnnouncementReminder;
use App\Models\AnnouncementReminderDelivery;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventListEmail;
use App\Models\PushDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Eliminar» for announcements and events (odd/tasks/observaciones.md O2):
 * whoever may edit one may delete it after confirming; what hangs from it
 * goes with it, and Auditoría keeps one line with the title and who
 * deleted it. «Retirar» stays.
 */
class DeleteAnnouncementsAndEventsTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'America/Bogota'));
        Storage::fake('public');

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create(['name' => 'Editora Segurex']);
        $this->editor->assignRole('announcements_editor');
    }

    // --- Policy ---------------------------------------------------------------

    public function test_whoever_may_edit_it_may_delete_it(): void
    {
        $mine = Announcement::factory()->forCompanies($this->segurex)->create();
        $theirs = Announcement::factory()->forCompanies($this->derivados)->create();
        $all = Announcement::factory()->create();
        $mineEvent = Event::factory()->forCompanies($this->segurex)->create();
        $allEvent = Event::factory()->create();

        foreach ([$mine, $theirs, $all, $mineEvent, $allEvent] as $item) {
            $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $item));
        }

        $this->assertTrue(Gate::forUser($this->editor)->allows('delete', $mine));
        $this->assertTrue(Gate::forUser($this->editor)->allows('delete', $mineEvent));
        $this->assertFalse(Gate::forUser($this->editor)->allows('delete', $theirs));
        $this->assertFalse(Gate::forUser($this->editor)->allows('delete', $all));
        $this->assertFalse(Gate::forUser($this->editor)->allows('delete', $allEvent));

        $nobody = User::factory()->microsoft($this->segurex)->create();
        $this->assertFalse(Gate::forUser($nobody)->allows('delete', $mine));
        $this->assertFalse(Gate::forUser($nobody)->allows('delete', $mineEvent));
    }

    // --- Announcements --------------------------------------------------------

    public function test_the_editor_asks_before_deleting_and_keeps_retirar(): void
    {
        $announcement = Announcement::factory()->create();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeHtml('wire:click="delete"')
            ->assertSeeHtml('wire:confirm="¿Eliminar este anuncio? Se borra para siempre')
            ->assertSeeText('Eliminar')
            ->assertSeeText('Retirar');

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertDontSeeHtml('wire:click="delete"');
    }

    public function test_deleting_an_announcement_removes_what_hangs_from_it_and_audits_one_line(): void
    {
        $reader = User::factory()->microsoft($this->segurex)->create();
        $announcement = Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create([
            'title' => 'Cierre de oficinas',
            'image_path' => Announcement::IMAGE_DIR.'/0/placeholder.webp',
        ]);
        $image = Announcement::IMAGE_DIR.'/'.$announcement->id.'/foto.webp';
        Storage::disk('public')->put($image, 'webp');
        $announcement->forceFill(['image_path' => $image])->saveQuietly();

        AnnouncementRead::query()->create(['announcement_id' => $announcement->id, 'user_id' => $reader->id, 'read_at' => now()]);
        $reminder = AnnouncementReminder::query()->create([
            'announcement_id' => $announcement->id,
            'requested_by' => $this->admin->id,
            'requested_at' => now(),
            'missing_count' => 1,
        ]);
        AnnouncementReminderDelivery::query()->create([
            'announcement_reminder_id' => $reminder->id,
            'person_key' => $reader->id,
            'user_id' => $reader->id,
            'status' => 'sent',
            'created_at' => now(),
        ]);
        AnnouncementListEmail::query()->create(['announcement_id' => $announcement->id, 'company_id' => $this->segurex->id, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);
        PushDelivery::query()->create(['announcement_id' => $announcement->id, 'endpoint_hash' => str_repeat('a', 64), 'status' => 'sent', 'created_at' => now()]);
        $other = Announcement::factory()->create();
        AnnouncementRead::query()->create(['announcement_id' => $other->id, 'user_id' => $reader->id, 'read_at' => now()]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('delete')
            ->assertRedirect(route('admin.announcements.index'));

        $this->assertModelMissing($announcement);
        $this->assertSame(0, AnnouncementRead::query()->where('announcement_id', $announcement->id)->count());
        $this->assertSame(0, AnnouncementReminder::query()->count());
        $this->assertSame(0, AnnouncementReminderDelivery::query()->count());
        $this->assertSame(0, AnnouncementListEmail::query()->count());
        $this->assertSame(0, PushDelivery::query()->count());
        $this->assertSame(0, DB::table('announcement_company')->where('announcement_id', $announcement->id)->count());
        Storage::disk('public')->assertMissing($image);
        $this->assertModelExists($other);
        $this->assertSame(1, AnnouncementRead::query()->count());

        $entries = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('subject_id', $announcement->id)
            ->whereIn('event', ['deleted', 'announcement.deleted'])->get();
        $this->assertCount(1, $entries);
        $entry = app(AuditPresenter::class)->activity($entries->sole());
        $this->assertSame('Eliminó el anuncio «Cierre de oficinas»', $entry->what);
        $this->assertSame('Jhon Castro', $entry->who);
        $this->assertSame([['label' => 'Título', 'old' => 'Cierre de oficinas', 'new' => '—']], $entry->changes);
    }

    public function test_an_editor_deletes_only_announcements_of_their_company(): void
    {
        $mine = Announcement::factory()->forCompanies($this->segurex)->create();
        $all = Announcement::factory()->create();

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $mine])
            ->call('delete')
            ->assertRedirect(route('admin.announcements.index'));
        $this->assertModelMissing($mine);

        // The editor never opens it, but a crafted call is refused all the same.
        $component = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $all]);
        Livewire::actingAs($this->editor);
        $component->call('delete')->assertForbidden();
        $this->assertModelExists($all);
    }

    // --- Events -----------------------------------------------------------------

    public function test_the_event_editor_asks_before_deleting_and_keeps_retirar(): void
    {
        $event = Event::factory()->create();

        Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $event])
            ->assertSeeHtml('wire:click="delete"')
            ->assertSeeHtml('wire:confirm="¿Eliminar este evento? Se borra para siempre')
            ->assertSeeText('Eliminar')
            ->assertSeeText('Retirar');

        Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->assertDontSeeHtml('wire:click="delete"');
    }

    public function test_deleting_an_event_removes_what_hangs_from_it_and_audits_one_line(): void
    {
        $event = Event::factory()->forCompanies($this->segurex)->create(['title' => 'Pausa activa', 'email_lists' => true]);
        EventListEmail::query()->create(['event_id' => $event->id, 'company_id' => $this->segurex->id, 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()]);

        Livewire::actingAs($this->editor)->test(EventEditor::class, ['event' => $event])
            ->call('delete')
            ->assertRedirect(route('admin.events.index'));

        $this->assertModelMissing($event);
        $this->assertSame(0, EventListEmail::query()->count());
        $this->assertSame(0, DB::table('event_company')->count());

        $entries = Activity::query()->where('log_name', Event::AUDIT_LOG)->where('subject_id', $event->id)
            ->whereIn('event', ['deleted', 'event.deleted'])->get();
        $this->assertCount(1, $entries);
        $entry = app(AuditPresenter::class)->activity($entries->sole());
        $this->assertSame('Eliminó el evento «Pausa activa»', $entry->what);
        $this->assertSame('Editora Segurex', $entry->who);
    }

    public function test_an_editor_cannot_delete_an_event_for_every_company(): void
    {
        $all = Event::factory()->create();

        $component = Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $all]);
        Livewire::actingAs($this->editor);
        $component->call('delete')->assertForbidden();

        $this->assertModelExists($all);
    }
}
