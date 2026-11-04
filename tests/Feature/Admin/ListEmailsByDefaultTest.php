<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\AnnouncementEditor;
use App\Livewire\Admin\EventEditor;
use App\Models\Announcement;
use App\Models\AnnouncementListEmail;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventListEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Jhon's review of 2026-11-04 (odd/tasks/observaciones.md O1): an
 * announcement or an event goes to the all-staff list of each company it
 * addresses unless the box is unticked, and a company without a list (or a
 * sender mailbox) is named in the editor and in Auditoría instead of being
 * skipped in silence. Both companies, each on its own.
 */
class ListEmailsByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'domains' => ['segurex.com']]);
        $this->derivados = Company::factory()
            ->withListEmail('todos@derivadoscol.com', 'derivados.info@derivadoscol.com')
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'domains' => ['derivadoscol.com']]);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');
    }

    // --- Announcements ------------------------------------------------------

    public function test_a_new_announcement_emails_every_list_unless_the_box_is_unticked(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSet('emailLists', true)
            ->set('title', 'Cierre de oficinas')
            ->set('body', 'Las oficinas cierran el viernes.')
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::query()->sole();
        $this->assertTrue($announcement->email_lists);
        $this->assertEqualsCanonicalizing(
            [$this->segurex->id, $this->derivados->id],
            AnnouncementListEmail::query()->pluck('company_id')->all(),
        );
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_each_company_gets_its_own_announcement_email(): void
    {
        foreach ([$this->segurex, $this->derivados] as $company) {
            Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
                ->set('title', 'Solo '.$company->short_name)
                ->set('body', 'Texto.')
                ->set('audience', Announcement::AUDIENCE_COMPANIES)
                ->set('companyIds', [(string) $company->id])
                ->call('save')
                ->assertHasNoErrors();

            $announcement = Announcement::query()->where('title', 'Solo '.$company->short_name)->sole();
            $this->assertSame([$company->id], $announcement->listEmails()->pluck('company_id')->all());
        }
    }

    public function test_unticking_the_box_sends_nothing(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Sin correo')
            ->set('body', 'Texto.')
            ->set('emailLists', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Announcement::query()->sole()->email_lists);
        $this->assertSame(0, AnnouncementListEmail::query()->count());
    }

    public function test_an_existing_announcement_keeps_its_choice(): void
    {
        $announcement = Announcement::factory()->create(['email_lists' => false]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSet('emailLists', false);
    }

    public function test_a_company_without_a_list_is_named_in_the_announcement_editor_and_audited(): void
    {
        $this->derivados->forceFill(['all_staff_email' => null])->saveQuietly();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText('Derivados no tiene lista de correo configurada; el anuncio no se enviará por correo a esa empresa.')
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->segurex->id])
            ->assertDontSeeText('no tiene lista de correo configurada')
            ->set('companyIds', [(string) $this->derivados->id])
            ->assertSeeText('Derivados no tiene lista de correo configurada; el anuncio no se enviará por correo a esa empresa.')
            ->set('title', 'Solo Derivados')
            ->set('body', 'Texto.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, AnnouncementListEmail::query()->count());

        $activity = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'announcement.list_email_skipped')->sole();
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertSame('No envió por correo, por falta de lista, el anuncio «Solo Derivados»', $entry->what);
        $this->assertSame('Jhon Castro', $entry->who);
        $this->assertSame([['label' => 'Empresas', 'old' => '—', 'new' => 'Derivados']], $entry->changes);
    }

    public function test_two_companies_without_a_list_are_named_together(): void
    {
        $this->segurex->forceFill(['mail_sender' => null])->saveQuietly();
        $this->derivados->forceFill(['all_staff_email' => null])->saveQuietly();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText('Derivados y Segurex no tienen lista de correo configurada; el anuncio no se enviará por correo a esas empresas.');
    }

    public function test_no_skip_is_audited_when_the_box_is_unticked_or_every_list_is_configured(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Todas configuradas')
            ->set('body', 'Texto.')
            ->call('save');

        $this->derivados->forceFill(['all_staff_email' => null])->saveQuietly();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Sin correo')
            ->set('body', 'Texto.')
            ->set('emailLists', false)
            ->call('save');

        $this->assertSame(0, Activity::query()->where('event', 'announcement.list_email_skipped')->count());
    }

    // --- Events -------------------------------------------------------------

    public function test_a_new_event_emails_every_list_unless_the_box_is_unticked(): void
    {
        $this->newEvent()
            ->assertSet('emailLists', true)
            ->assertSeeText('Enviar también por correo a la lista de cada empresa')
            ->assertSeeText('Lo reciben: Derivados (todos@derivadoscol.com) y Segurex (todos@segurex.com).')
            ->call('save')
            ->assertHasNoErrors();

        $event = Event::query()->sole();
        $this->assertTrue($event->email_lists);
        $this->assertEqualsCanonicalizing(
            [$this->segurex->id, $this->derivados->id],
            EventListEmail::query()->pluck('company_id')->all(),
        );
        Http::assertNothingSent();

        $this->newEvent('Sin correo')->set('emailLists', false)->call('save')->assertHasNoErrors();
        $this->assertFalse(Event::query()->where('title', 'Sin correo')->sole()->email_lists);
        $this->assertSame(2, EventListEmail::query()->count());
    }

    public function test_each_company_gets_its_own_event_email(): void
    {
        foreach ([$this->segurex, $this->derivados] as $company) {
            $this->newEvent('Solo '.$company->short_name)
                ->set('audience', Event::AUDIENCE_COMPANIES)
                ->set('companyIds', [(string) $company->id])
                ->call('save')
                ->assertHasNoErrors();

            $event = Event::query()->where('title', 'Solo '.$company->short_name)->sole();
            $this->assertSame([$company->id], $event->listEmails()->pluck('company_id')->all());
        }
    }

    public function test_a_company_without_a_list_is_named_in_the_event_editor_and_audited(): void
    {
        $this->segurex->forceFill(['all_staff_email' => null, 'mail_sender' => null])->saveQuietly();

        $this->newEvent('Pausa activa')
            ->assertSeeText('Segurex no tiene lista de correo configurada; el evento no se enviará por correo a esa empresa.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->derivados->id], EventListEmail::query()->pluck('company_id')->all());

        $activity = Activity::query()->where('log_name', Event::AUDIT_LOG)->where('event', 'event.list_email_skipped')->sole();
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertSame('No envió por correo, por falta de lista, el evento «Pausa activa»', $entry->what);
        $this->assertSame([['label' => 'Empresas', 'old' => '—', 'new' => 'Segurex']], $entry->changes);
    }

    public function test_the_event_editor_shows_how_the_email_went(): void
    {
        $event = Event::factory()->create(['email_lists' => true]);
        EventListEmail::query()->create([
            'event_id' => $event->id,
            'company_id' => $this->segurex->id,
            'status' => EventListEmail::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now()->subHour(),
        ]);
        EventListEmail::query()->create([
            'event_id' => $event->id,
            'company_id' => $this->derivados->id,
            'status' => EventListEmail::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'ErrorAccessDenied',
        ]);

        Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $event])
            ->assertSet('emailLists', true)
            ->assertSeeText('Correo a las listas')
            ->assertSeeText('Segurex · todos@segurex.com')
            ->assertSeeText('Enviado el 4 de noviembre a las 09:00')
            ->assertSeeText('Derivados · todos@derivadoscol.com')
            ->assertSeeText('No se pudo enviar (ErrorAccessDenied)')
            ->assertSeeText('Reintentar');
    }

    public function test_retry_sets_a_failed_event_email_back_to_pending_and_is_audited(): void
    {
        $event = Event::factory()->create(['email_lists' => true, 'title' => 'Pausa activa']);
        $row = EventListEmail::query()->create([
            'event_id' => $event->id,
            'company_id' => $this->derivados->id,
            'status' => EventListEmail::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'ErrorAccessDenied',
        ]);

        Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $event])
            ->call('retryListEmail', $row->id)
            ->assertHasNoErrors();

        $this->assertSame(EventListEmail::STATUS_PENDING, $row->fresh()->status);
        $activity = Activity::query()->where('log_name', Event::AUDIT_LOG)->where('event', 'event.list_email_retried')->sole();
        $this->assertSame('Reintentó el correo a la lista de el evento «Pausa activa»', app(AuditPresenter::class)->activity($activity)->what);
    }

    // --- Review fixes (odd/tasks/observaciones.md, R1) ---------------------------

    public function test_editing_an_event_without_the_emails_keeps_them_off(): void
    {
        $event = Event::factory()->create(['email_lists' => false, 'title' => 'Sin correo']);

        Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $event])
            ->assertSet('emailLists', false)
            ->set('title', 'Sin correo, editado')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($event->fresh()->email_lists);
        $this->assertSame(0, EventListEmail::query()->count());
    }

    public function test_ticking_the_box_on_a_saved_announcement_audits_the_skipped_companies(): void
    {
        $this->derivados->forceFill(['all_staff_email' => null])->saveQuietly();
        $announcement = Announcement::factory()->create(['email_lists' => false, 'title' => 'Cierre']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->set('emailLists', true)
            ->call('save')
            ->assertHasNoErrors();

        $activity = Activity::query()->where('event', 'announcement.list_email_skipped')->sole();
        $this->assertSame(['companies' => ['Derivados']], $activity->properties['attributes']);
    }

    public function test_ticking_the_box_on_a_saved_event_audits_the_skipped_companies(): void
    {
        $this->segurex->forceFill(['mail_sender' => null])->saveQuietly();
        $event = Event::factory()->create(['email_lists' => false]);

        Livewire::actingAs($this->admin)->test(EventEditor::class, ['event' => $event])
            ->set('emailLists', true)
            ->call('save')
            ->assertHasNoErrors();

        $activity = Activity::query()->where('event', 'event.list_email_skipped')->sole();
        $this->assertSame(['companies' => ['Segurex']], $activity->properties['attributes']);
    }

    public function test_addresses_outside_the_company_domains_are_named_as_such(): void
    {
        $this->derivados->forceFill(['mail_sender' => 'info@gmail.com'])->saveQuietly();
        $this->segurex->forceFill(['all_staff_email' => null])->saveQuietly();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText('Derivados tiene la lista de correo o el buzón remitente fuera de sus dominios; el anuncio no se enviará por correo a esa empresa.')
            ->assertSeeText('Segurex no tiene lista de correo configurada; el anuncio no se enviará por correo a esa empresa.')
            ->assertDontSeeText('Derivados no tiene lista de correo configurada');
    }

    private function newEvent(string $title = 'Pausa activa'): Testable
    {
        return Livewire::actingAs($this->admin)->test(EventEditor::class)
            ->set('title', $title)
            ->set('startsAt', '2026-11-10T15:00');
    }
}
