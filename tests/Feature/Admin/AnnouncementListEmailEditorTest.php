<?php

namespace Tests\Feature\Admin;

use App\Announcements\AnnouncementListEmails;
use App\Audit\AuditPresenter;
use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\AnnouncementListEmail;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Enviar también por correo a la lista de cada empresa» in the announcement
 * editor (odd/tasks/inicio-fase-2.md WU4): who may edit the announcement
 * ticks it, sees which lists get it and how each email went, and retries
 * a failed one. The cron sends it
 * (tests/Feature/Console/SendAnnouncementListEmailsCommandTest.php).
 */
class AnnouncementListEmailEditorTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKBOX = 'Enviar también por correo a la lista de cada empresa';

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex', 'domains' => ['segurex.com']]);
        $this->derivados = Company::factory()
            ->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'domains' => ['derivadoscol.com']]);

        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');

        $this->editor = User::factory()->microsoft($this->segurex)->create(['name' => 'Editora Segurex']);
        $this->editor->assignRole('announcements_editor');
    }

    public function test_the_option_is_hidden_when_no_company_of_the_audience_has_a_list(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->derivados->id])
            ->assertDontSeeText(self::CHECKBOX);
    }

    public function test_the_option_names_the_lists_that_will_get_it(): void
    {
        $this->giveDerivadosAList();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText(self::CHECKBOX)
            ->assertSeeText('Lo reciben: Derivados (todos@derivadoscol.com) y Segurex (todos@segurex.com).')
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->segurex->id])
            ->assertSeeText('Lo reciben: Segurex (todos@segurex.com).')
            ->assertDontSeeText('todos@derivadoscol.com');
    }

    public function test_an_inactive_company_or_one_with_half_the_data_is_not_listed(): void
    {
        $this->segurex->update(['is_active' => false]);
        $this->derivados->forceFill(['all_staff_email' => 'todos@derivadoscol.com'])->saveQuietly();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertDontSeeText(self::CHECKBOX);
    }

    public function test_an_admin_saves_the_option_and_it_is_audited(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Cierre de oficinas')
            ->set('body', 'Las oficinas cierran el viernes.')
            ->set('emailLists', true)
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::query()->sole();
        $this->assertTrue($announcement->email_lists);

        $activity = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'created')->sole();
        $this->assertTrue($activity->properties['attributes']['email_lists']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSet('emailLists', true)
            ->set('emailLists', false)
            ->call('save');

        $changed = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $entry = app(AuditPresenter::class)->activity($changed);
        $this->assertSame([['label' => 'Enviar por correo a las listas', 'old' => 'Sí', 'new' => 'No']], $entry->changes);
    }

    public function test_the_option_is_not_kept_when_no_company_of_the_audience_can_get_it(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Solo Derivados')
            ->set('body', 'Texto.')
            ->set('emailLists', true)
            ->set('audience', Announcement::AUDIENCE_COMPANIES)
            ->set('companyIds', [(string) $this->derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Announcement::query()->sole()->email_lists);
    }

    public function test_an_editor_only_addresses_the_list_of_their_own_company(): void
    {
        $this->giveDerivadosAList();

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class)
            ->assertSeeText('Lo reciben: Segurex (todos@segurex.com).')
            ->assertDontSeeText('todos@derivadoscol.com')
            ->set('title', 'Para Derivados')
            ->set('body', 'Texto.')
            ->set('emailLists', true)
            ->set('companyIds', [(string) $this->derivados->id])
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Announcement::query()->count());
    }

    public function test_an_editor_cannot_open_an_announcement_of_another_company(): void
    {
        $this->giveDerivadosAList();
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->derivados)->create();

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertForbidden();
    }

    public function test_it_shows_how_the_email_went_for_each_company(): void
    {
        $this->giveDerivadosAList();
        $third = Company::factory()->withListEmail('todos@tercera.com', 'info@tercera.com')->create(['short_name' => 'Tercera', 'domains' => ['tercera.com']]);
        $announcement = Announcement::factory()->emailLists()->create();
        $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_SENT, ['sent_at' => now()->subHour()]);
        $this->row($announcement, $this->derivados, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'ErrorAccessDenied']);
        $this->row($announcement, $third, AnnouncementListEmail::STATUS_PENDING, ['attempts' => 0]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Correo a las listas')
            ->assertSeeText('Segurex · todos@segurex.com')
            ->assertSeeText('Enviado el 30 de octubre a las 09:00')
            ->assertSeeText('Derivados · todos@derivadoscol.com')
            ->assertSeeText('No se pudo enviar (ErrorAccessDenied)')
            ->assertSeeText('El buzón remitente no tiene permiso de envío. Revisa los pasos de Exchange en docs/despliegue.md.')
            ->assertSeeText('Tercera · todos@tercera.com')
            ->assertSeeText('Pendiente: sale en los próximos minutos.')
            ->assertSeeText('Reintentar');
    }

    public function test_a_scheduled_announcement_says_when_its_email_goes_out(): void
    {
        $announcement = $this->queued(Announcement::factory()->emailLists()->create(['starts_at' => now()->addDay()]));

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Pendiente: sale cuando se publique, el 31 de octubre a las 10:00.');
    }

    public function test_a_withdrawn_announcement_says_its_email_will_not_go_out(): void
    {
        $announcement = $this->queued(Announcement::factory()->emailLists()->withdrawn()->create());

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('No se envía mientras el anuncio no esté publicado.');
    }

    public function test_retry_sets_a_failed_email_back_to_pending_and_is_audited(): void
    {
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create(['title' => 'Cierre de oficinas']);
        $row = $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'ErrorAccessDenied']);

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('retryListEmail', $row->id)
            ->assertHasNoErrors()
            ->assertSeeText('El correo a la lista de Segurex sale de nuevo en los próximos minutos.')
            ->assertSeeText('Pendiente')
            ->assertDontSeeText('Reintentar');

        $row->refresh();
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, $row->status);
        $this->assertTrue($row->retried_at->equalTo(now()));

        $activity = Activity::query()->where('event', 'announcement.list_email_retried')->sole();
        $this->assertTrue($activity->causer->is($this->editor));
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertSame('Reintentó el correo a la lista de el anuncio «Cierre de oficinas»', $entry->what);
        $this->assertContains(['label' => 'Empresa', 'old' => '—', 'new' => 'Segurex'], $entry->changes);
        $this->assertContains(['label' => 'Error anterior', 'old' => '—', 'new' => 'ErrorAccessDenied'], $entry->changes);
    }

    public function test_retry_is_allowed_once_every_ten_minutes_per_email(): void
    {
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $row = $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_FAILED, [
            'last_error' => 'ErrorAccessDenied',
            'retried_at' => now()->subMinutes(9),
        ]);

        $component = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Podrás reintentar a las 10:01.')
            ->call('retryListEmail', $row->id)
            ->assertHasErrors('listEmail');
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->fresh()->status);

        $this->travel(1)->minutes();
        $component->call('retryListEmail', $row->id)->assertHasNoErrors();
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, $row->fresh()->status);
        $this->assertSame(1, Activity::query()->where('event', 'announcement.list_email_retried')->count());
    }

    public function test_only_a_failed_email_of_this_announcement_can_be_retried(): void
    {
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $other = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $sent = $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_SENT, ['sent_at' => now()]);
        $foreign = $this->row($other, $this->segurex, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'HTTP 500']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('retryListEmail', $sent->id)
            ->assertHasErrors('listEmail')
            ->call('retryListEmail', $foreign->id)
            ->assertHasErrors('listEmail');

        $this->assertSame(AnnouncementListEmail::STATUS_SENT, $sent->fresh()->status);
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $foreign->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'announcement.list_email_retried')->count());
    }

    public function test_an_editor_neither_sees_nor_retries_the_email_of_another_company(): void
    {
        $this->giveDerivadosAList();
        // Addressed to Derivados before; now only to Segurex, so the editor may open it.
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $row = $this->row($announcement, $this->derivados, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'ErrorAccessDenied']);

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertDontSeeText('todos@derivadoscol.com')
            ->call('retryListEmail', $row->id)
            ->assertHasErrors('listEmail');

        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->fresh()->status);
    }

    public function test_someone_who_lost_the_role_cannot_retry(): void
    {
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $row = $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'HTTP 500']);

        $component = Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement]);
        $this->editor->removeRole('announcements_editor');

        $component->call('retryListEmail', $row->id)->assertForbidden();
        $this->assertSame(AnnouncementListEmail::STATUS_FAILED, $row->fresh()->status);
    }

    public function test_saving_with_the_box_ticked_queues_one_email_per_company_that_can_get_it(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Cierre de oficinas')
            ->set('body', 'Las oficinas cierran el viernes.')
            ->set('emailLists', true)
            ->call('save')
            ->assertHasNoErrors();

        // Derivados has no list: nothing for it.
        $row = AnnouncementListEmail::query()->sole();
        $this->assertSame($this->segurex->id, $row->company_id);
        $this->assertSame(AnnouncementListEmail::STATUS_PENDING, $row->status);
        $this->assertSame(0, $row->attempts);
    }

    public function test_saving_without_the_box_queues_nothing(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', 'Cierre de oficinas')
            ->set('body', 'Texto.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, AnnouncementListEmail::query()->count());
    }

    public function test_a_company_added_later_gets_it_once_when_saved_again_and_the_first_one_not_again(): void
    {
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'SECRET-VALUE-NEVER-SHOWN',
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(null, 202),
        ]);
        $this->giveDerivadosAList();
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();
        $sent = $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_SENT, ['sent_at' => now()->subHour()]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->set('companyIds', [(string) $this->segurex->id, (string) $this->derivados->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();
        $this->artisan('portal:send-announcement-list-emails')->assertSuccessful();

        $sends = Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/'));
        $this->assertCount(1, $sends);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/derivados.info@derivadoscol.com/sendMail', $sends->first()[0]->url());
        $this->assertTrue($sent->fresh()->sent_at->equalTo(now()->subHour()));
        $this->assertSame(2, AnnouncementListEmail::query()->count());
    }

    public function test_a_company_that_can_get_it_but_was_not_queued_waits_for_a_save(): void
    {
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('Segurex · todos@segurex.com')
            ->assertSeeText('Sin enviar: guarda el anuncio con la casilla marcada para enviárselo.')
            ->assertDontSeeText('Pendiente');
    }

    public function test_a_company_with_an_address_outside_its_domains_is_not_shown_as_pending(): void
    {
        $this->segurex->forceFill(['mail_sender' => 'info@otro.com'])->saveQuietly();
        $announcement = Announcement::factory()->emailLists()->forCompanies($this->segurex)->create();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertDontSeeText('Pendiente')
            ->assertDontSeeText('Sin enviar')
            ->assertDontSeeText(self::CHECKBOX);
    }

    public function test_a_pending_email_of_a_company_that_can_no_longer_get_it_says_so(): void
    {
        $announcement = $this->queued(Announcement::factory()->emailLists()->forCompanies($this->segurex)->create());
        $this->segurex->update(['all_staff_email' => null, 'mail_sender' => null]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('No se envía: la empresa está inactiva o ya no tiene lista y buzón remitente.');
    }

    public function test_a_timeout_or_a_server_error_warns_that_the_email_may_have_left(): void
    {
        $this->giveDerivadosAList();
        $announcement = Announcement::factory()->emailLists()->create();
        $this->row($announcement, $this->segurex, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'Unavailable']);
        $this->row($announcement, $this->derivados, AnnouncementListEmail::STATUS_FAILED, ['last_error' => 'HTTP 503']);

        $html = Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])->html();

        $this->assertSame(2, substr_count($html, 'no se sabe si salió. Revisa la lista antes de reintentar.'));
    }

    public function test_a_title_with_line_breaks_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->set('title', "Aviso\n\n[Entra aquí](https://evil.example/login)")
            ->set('body', 'Texto.')
            ->call('save')
            ->assertHasErrors(['title' => 'not_regex'])
            ->assertSeeText('El título va en una sola línea.');

        $this->assertSame(0, Announcement::query()->count());
    }

    private function queued(Announcement $announcement): Announcement
    {
        app(AnnouncementListEmails::class)->queue($announcement);

        return $announcement;
    }

    private function giveDerivadosAList(): void
    {
        $this->derivados->update(['all_staff_email' => 'todos@derivadoscol.com', 'mail_sender' => 'derivados.info@derivadoscol.com']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(Announcement $announcement, Company $company, string $status, array $attributes = []): AnnouncementListEmail
    {
        return AnnouncementListEmail::query()->create([
            'announcement_id' => $announcement->id,
            'company_id' => $company->id,
            'status' => $status,
            'attempts' => 1,
            ...$attributes,
        ]);
    }
}
