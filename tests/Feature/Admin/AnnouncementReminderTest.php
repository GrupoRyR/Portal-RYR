<?php

namespace Tests\Feature\Admin;

use App\Announcements\AnnouncementReminders;
use App\Announcements\ReminderNotAllowed;
use App\Audit\AuditPresenter;
use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\AnnouncementReminder;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Enviar recordatorio» in the read report of a mandatory announcement
 * (odd/tasks/ajustes-inicio-y-tareas.md T5): whoever may edit it asks for
 * a reminder to the people who are missing, at most once every 24 hours
 * per announcement (checked on the server). The request only records it;
 * the cron sends the emails (tests/Feature/Console/SendAnnouncementRemindersCommandTest.php).
 */
class AnnouncementReminderTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create(['name' => 'Editora Segurex']);
        $this->editor->assignRole('announcements_editor');
    }

    public function test_an_editor_requests_a_reminder_and_only_the_request_is_recorded(): void
    {
        $announcement = $this->mandatoryForSegurex();
        User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Enviar recordatorio')
            ->call('sendReminder')
            ->assertHasNoErrors()
            ->assertSeeText('Recordatorio en camino')
            ->assertSeeText('Último recordatorio: pedido el 29 de octubre a las 10:00 por Editora Segurex')
            ->assertSeeText('Enviando…')
            ->assertDontSeeText('Enviar recordatorio');

        $reminder = AnnouncementReminder::query()->sole();
        $this->assertTrue($reminder->announcement->is($announcement));
        $this->assertTrue($reminder->requester->is($this->editor));
        $this->assertSame(2, $reminder->missing_count);
        $this->assertNull($reminder->finished_at);

        // The cron sends it, never the request.
        Notification::assertNothingSent();
    }

    public function test_a_second_reminder_within_24_hours_is_refused_on_the_server(): void
    {
        $announcement = $this->mandatoryForSegurex();

        $editor = Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('sendReminder')
            ->assertHasNoErrors();

        // A double click, or a page opened before the first one.
        $editor->call('sendReminder')
            ->assertHasErrors('reminder')
            ->assertSeeText('Ya se pidió un recordatorio de este anuncio. Podrás pedir otro el 30 de octubre a las 10:00.');
        $this->assertSame(1, AnnouncementReminder::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:59', 'America/Bogota'));
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertDontSeeText('Enviar recordatorio')
            ->assertSeeText('Podrás pedir otro recordatorio el 30 de octubre a las 10:00.')
            ->call('sendReminder')
            ->assertHasErrors('reminder');
        $this->assertSame(1, AnnouncementReminder::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Bogota'));
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Enviar recordatorio')
            ->call('sendReminder')
            ->assertHasNoErrors();
        $this->assertSame(2, AnnouncementReminder::query()->count());
    }

    public function test_the_limit_is_per_announcement(): void
    {
        $first = $this->mandatoryForSegurex();
        $second = $this->mandatoryForSegurex();

        foreach ([$first, $second] as $announcement) {
            Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
                ->call('sendReminder')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, AnnouncementReminder::query()->count());
    }

    public function test_no_reminder_when_everybody_read_it(): void
    {
        $announcement = $this->mandatoryForSegurex();
        $announcement->markReadBy($this->editor);

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Ya lo leyeron todos.')
            ->assertDontSeeText('Enviar recordatorio')
            ->call('sendReminder')
            ->assertHasErrors('reminder')
            ->assertSeeText('Ya lo leyeron todos.');

        $this->assertSame(0, AnnouncementReminder::query()->count());
    }

    public function test_no_reminder_for_an_announcement_that_is_not_shown_now(): void
    {
        $announcements = [
            Announcement::factory()->mandatory()->forCompanies($this->segurex)->withdrawn()->create(),
            Announcement::factory()->mandatory()->forCompanies($this->segurex)->expired()->create(),
            Announcement::factory()->mandatory()->forCompanies($this->segurex)->scheduled()->create(),
        ];

        foreach ($announcements as $announcement) {
            Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
                ->assertSeeText('Personas que faltan')
                ->assertDontSeeText('Enviar recordatorio')
                ->call('sendReminder')
                ->assertHasErrors('reminder')
                ->assertSeeText('Solo se pide un recordatorio de un anuncio publicado con lectura obligatoria.');
        }

        $this->assertSame(0, AnnouncementReminder::query()->count());
    }

    public function test_no_reminder_for_an_announcement_that_is_not_mandatory(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create();

        $this->expectException(ReminderNotAllowed::class);

        app(AnnouncementReminders::class)->request($announcement, $this->editor);
    }

    public function test_someone_who_may_no_longer_edit_it_cannot_request_one(): void
    {
        $announcement = $this->mandatoryForSegurex();

        $editor = Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement]);
        $this->editor->removeRole('announcements_editor');

        $editor->call('sendReminder')->assertForbidden();

        $this->assertSame(0, AnnouncementReminder::query()->count());
    }

    public function test_the_request_is_audited_with_the_missing_people(): void
    {
        $announcement = $this->mandatoryForBoth();
        $announcement->update(['title' => 'Nueva política de contraseñas']);
        $this->linked('Ana Pérez');

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('sendReminder')
            ->assertHasNoErrors();

        $entry = Activity::query()->where('event', 'announcement.reminder_requested')->sole();
        $this->assertSame(Announcement::AUDIT_LOG, $entry->log_name);
        $this->assertTrue($entry->causer->is($this->admin));
        $this->assertTrue($entry->subject->is($announcement));
        // The editor and Ana (two linked accounts, one person).
        $this->assertSame(2, $entry->properties['attributes']['missing_people']);

        $row = app(AuditPresenter::class)->activity($entry);
        $this->assertSame('Pidió un recordatorio de lectura para el anuncio «Nueva política de contraseñas»', $row->what);
        $this->assertSame([['label' => 'Personas que faltan', 'old' => '—', 'new' => '2']], $row->changes);
    }

    public function test_the_report_shows_how_many_people_got_the_last_reminder(): void
    {
        $announcement = $this->mandatoryForBoth();
        $this->linked('Ana Pérez');
        User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('sendReminder');

        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Último recordatorio: pedido el 29 de octubre a las 10:00 por Jhon Castro · enviado a 3 personas.')
            ->assertDontSeeText('Enviando…');
    }

    public function test_the_report_says_one_person_in_singular(): void
    {
        $announcement = $this->mandatoryForSegurex();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('sendReminder');
        $this->artisan('portal:send-announcement-reminders')->assertSuccessful();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('enviado a 1 persona.');
    }

    /** One an announcements editor of Segurex may edit. */
    private function mandatoryForSegurex(): Announcement
    {
        return Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
    }

    /** Only admins may edit it. */
    private function mandatoryForBoth(): Announcement
    {
        return Announcement::factory()->mandatory()->forCompanies($this->segurex, $this->derivados)->create();
    }

    /**
     * One person with an account in Segurex and one in Derivados.
     *
     * @return array{User, User}
     */
    private function linked(string $name): array
    {
        $person = Person::query()->create();

        return [
            User::factory()->microsoft($this->segurex)->create(['name' => $name, 'person_id' => $person->id]),
            User::factory()->microsoft($this->derivados)->create(['name' => $name, 'person_id' => $person->id]),
        ];
    }
}
