<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Mandatory reading in Admin (odd/tasks/inicio-fase-2.md WU1): whoever may
 * edit an announcement marks it mandatory and sees «X de Y cuentas lo
 * leyeron» and the accounts that are missing. Y is the accounts that see
 * it: those of the announcement's active companies, without emergency or
 * guest accounts. Per account, not per person: someone with an account in
 * two companies confirms once with each.
 */
class AnnouncementReadReportTest extends TestCase
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

        $this->editor = User::factory()->microsoft($this->segurex)->create(['name' => 'Editora Segurex']);
        $this->editor->assignRole('announcements_editor');
    }

    // --- Marking ----------------------------------------------------------

    public function test_an_admin_marks_an_announcement_as_mandatory_and_it_is_logged(): void
    {
        $announcement = Announcement::factory()->create();

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSet('requiresRead', false)
            ->assertSeeText('Lectura obligatoria')
            ->set('requiresRead', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($announcement->fresh()->requires_read);

        $entry = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->properties['attributes']['requires_read']);
        $this->assertFalse($entry->properties['old']['requires_read']);
    }

    public function test_a_new_announcement_can_be_mandatory(): void
    {
        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class)
            ->set('title', 'Nueva política de contraseñas')
            ->set('body', 'Cámbiala antes del viernes.')
            ->set('requiresRead', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Announcement::query()->sole()->requires_read);
    }

    public function test_an_editor_cannot_mark_another_companys_announcement(): void
    {
        $all = Announcement::factory()->create();
        $theirs = Announcement::factory()->forCompanies($this->derivados)->create();

        foreach ([$all, $theirs] as $announcement) {
            Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])->assertForbidden();
        }

        $nobody = User::factory()->microsoft($this->segurex)->create();
        $this->actingAs($nobody)->get(route('admin.announcements.edit', $all))->assertForbidden();

        $this->assertFalse($all->fresh()->requires_read);
        $this->assertFalse($theirs->fresh()->requires_read);
    }

    // --- Report -----------------------------------------------------------

    public function test_the_report_counts_the_people_of_the_announcement_company(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();

        $ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez']);
        User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);
        $guest = User::factory()->microsoft($this->segurex)->create(['name' => 'Invitado Externo']);
        $guest->forceFill(['is_guest' => true])->save();
        $other = User::factory()->microsoft($this->derivados)->create(['name' => 'Carla Derivados']);
        User::factory()->emergency()->create();

        $announcement->markReadBy($ana);
        $announcement->markReadBy($guest);
        $announcement->markReadBy($other); // Left over from an earlier audience: not counted.

        // Segurex: the editor, Ana and Luis (not the guest).
        $this->assertSame(['read' => 1, 'total' => 3], $announcement->readCounts());
        $this->assertSame([
            ['name' => 'Editora Segurex', 'company' => 'Segurex'],
            ['name' => 'Luis Gómez', 'company' => 'Segurex'],
        ], $announcement->pendingReaders());

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('1 de 3 cuentas lo leyeron')
            ->assertSeeText('Cuentas que faltan (2)')
            ->assertSeeText('Luis Gómez')
            ->assertSeeText('Editora Segurex')
            ->assertDontSeeText('Ana Pérez')
            ->assertDontSeeText('Invitado Externo')
            ->assertDontSeeText('Carla Derivados');
    }

    public function test_an_announcement_for_every_company_counts_every_active_company(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $closed = Company::factory()->create(['is_active' => false]);

        $carla = User::factory()->microsoft($this->derivados)->create(['name' => 'Carla Derivados']);
        User::factory()->microsoft($closed)->create(['name' => 'Persona de empresa inactiva']);
        $announcement->markReadBy($carla);

        // The Segurex editor and Carla; the admin has no company.
        $this->assertSame(['read' => 1, 'total' => 2], $announcement->readCounts());

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('1 de 2 cuentas lo leyeron')
            ->assertSeeText('Editora Segurex')
            ->assertDontSeeText('Persona de empresa inactiva');
    }

    public function test_only_mandatory_announcements_have_a_report(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex)->create();

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertDontSee('data-read-report', false);
    }

    public function test_only_people_who_may_edit_the_announcement_see_who_read_it(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
        User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);
        $derivadosEditor = User::factory()->microsoft($this->derivados)->create();
        $derivadosEditor->assignRole('announcements_editor');

        $this->assertTrue($this->editor->can('viewReads', $announcement));
        $this->assertTrue($this->admin->can('viewReads', $announcement));
        $this->assertFalse($derivadosEditor->can('viewReads', $announcement));
        $this->assertFalse(User::factory()->microsoft($this->segurex)->create()->can('viewReads', $announcement));

        $this->actingAs($derivadosEditor)->get(route('admin.announcements.edit', $announcement))->assertForbidden();

        // People who only read it see their own state, never the others'.
        $this->actingAs(User::factory()->microsoft($this->segurex)->create())->get(route('announcements.index'))
            ->assertOk()
            ->assertDontSeeText('lo leyeron')
            ->assertDontSeeText('Luis Gómez');
    }

    public function test_the_report_disappears_when_the_editor_loses_the_role(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();

        $editor = Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('0 de 1 cuenta lo leyó')
            ->assertSee('data-read-report', false);

        $this->editor->removeRole('announcements_editor');

        $editor->set('title', 'Otro título')->assertDontSee('data-read-report', false);
    }

    public function test_an_account_whose_sign_in_has_not_said_if_it_is_a_guest_counts(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
        $unknown = User::factory()->microsoft($this->segurex)->create(['name' => 'Aún sin saber']);
        $unknown->forceFill(['is_guest' => null])->save();

        // The editor and the account not known yet.
        $this->assertSame(['read' => 0, 'total' => 2], $announcement->readCounts());
    }

    public function test_the_list_of_missing_accounts_stops_at_the_limit(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
        User::factory()->microsoft($this->segurex)->count(Announcement::PENDING_READERS_LIMIT + 2)->create();
        $missing = Announcement::PENDING_READERS_LIMIT + 3; // With the editor.

        $this->assertCount(Announcement::PENDING_READERS_LIMIT, $announcement->pendingReaders());

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText("0 de {$missing} cuentas lo leyeron")
            ->assertSeeText("Cuentas que faltan ({$missing})")
            ->assertSeeText('Y 3 más.');
    }

    public function test_an_account_that_moves_to_another_company_follows_its_company(): void
    {
        $segurexOnly = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
        $forAll = Announcement::factory()->mandatory()->create();
        $ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez']);
        User::factory()->microsoft($this->derivados)->create();

        $segurexOnly->markReadBy($ana);
        $forAll->markReadBy($ana);
        $this->assertSame(['read' => 1, 'total' => 2], $segurexOnly->readCounts());
        $this->assertSame(['read' => 1, 'total' => 3], $forAll->readCounts());

        // Ana is now in Derivados: she leaves both X and Y of the Segurex
        // announcement; for every company she still counts, as read.
        $ana->forceFill(['company_id' => $this->derivados->id])->save();
        $this->assertSame(['read' => 0, 'total' => 1], $segurexOnly->readCounts());
        $this->assertSame(['read' => 1, 'total' => 3], $forAll->readCounts());

        // The read is kept: back in Segurex, it counts again.
        $ana->forceFill(['company_id' => $this->segurex->id])->save();
        $this->assertSame(['read' => 1, 'total' => 2], $segurexOnly->readCounts());
    }

    public function test_typing_in_the_form_does_not_recount_the_reads(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();
        User::factory()->microsoft($this->segurex)->create(['name' => 'Luis Gómez']);

        $editor = Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSeeText('0 de 2 cuentas lo leyeron');

        $announcement->markReadBy($this->editor);

        $readQueries = 0;
        DB::listen(function ($query) use (&$readQueries) {
            if (str_contains($query->sql, 'announcement_reads')) {
                $readQueries++;
            }
        });

        $editor->set('title', 'Otro título')
            ->set('body', 'Otro texto')
            ->assertSeeText('0 de 2 cuentas lo leyeron')
            ->assertSeeText('Luis Gómez');

        $this->assertSame(0, $readQueries);
    }

    public function test_the_report_cannot_be_called_from_the_page(): void
    {
        // A public computed method: Livewire refuses it as an action, so
        // `$wire.readReport()` cannot skip the viewReads check of render().
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create();

        $this->expectException(CannotCallComputedDirectlyException::class);

        Livewire::actingAs($this->editor)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->call('readReport');
    }
}
