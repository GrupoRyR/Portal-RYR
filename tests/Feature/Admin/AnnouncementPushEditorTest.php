<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\AnnouncementEditor;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Push\PushTestKeys;
use Tests\TestCase;

/**
 * «Enviar al celular» in the announcement editor (odd/tasks/avisos.md T2):
 * whoever may publish the announcement ticks it, and the cron sends a
 * notice to the devices of the people who see it
 * (tests/Feature/Console/SendAnnouncementPushesCommandTest.php). Hidden
 * while the VAPID settings are missing.
 */
class AnnouncementPushEditorTest extends TestCase
{
    use PushTestKeys;
    use RefreshDatabase;

    private const CHECKBOX = 'Enviar al celular';

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->configurePush();

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->admin = User::factory()->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('portal_admin');
    }

    public function test_an_admin_ticks_it_and_it_is_audited(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSeeText(self::CHECKBOX)
            ->set('title', 'Corte de luz')
            ->set('body', 'Hoy a las 15:00 se va la luz en la sede.')
            ->set('pushPhone', true)
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::query()->sole();
        $this->assertTrue($announcement->push_phone);

        $created = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'created')->sole();
        $this->assertTrue($created->properties['attributes']['push_phone']);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertSet('pushPhone', true)
            ->set('pushPhone', false)
            ->call('save');

        $changed = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $entry = app(AuditPresenter::class)->activity($changed);
        $this->assertSame([['label' => 'Enviar al celular', 'old' => 'Sí', 'new' => 'No']], $entry->changes);
    }

    public function test_an_announcements_editor_ticks_it_for_their_company(): void
    {
        $editor = User::factory()->microsoft($this->segurex)->create();
        $editor->assignRole('announcements_editor');

        Livewire::actingAs($editor)->test(AnnouncementEditor::class)
            ->assertSeeText(self::CHECKBOX)
            ->set('title', 'Simulacro')
            ->set('body', 'Simulacro de evacuación a las 10:00.')
            ->set('pushPhone', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Announcement::query()->sole()->push_phone);
    }

    public function test_it_is_off_by_default(): void
    {
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class)
            ->assertSet('pushPhone', false)
            ->set('title', 'Sin aviso')
            ->set('body', 'Texto.')
            ->call('save');

        $this->assertFalse(Announcement::query()->sole()->push_phone);
    }

    public function test_without_the_vapid_settings_it_is_hidden_and_the_saved_value_is_kept(): void
    {
        $announcement = Announcement::factory()->create(['push_phone' => true]);
        config(['push.vapid.public_key' => null]);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->assertDontSeeText(self::CHECKBOX)
            ->set('pushPhone', false)
            ->set('title', 'Otro título')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($announcement->fresh()->push_phone);
        $this->assertSame('Otro título', $announcement->fresh()->title);
    }

    public function test_ticking_it_again_lets_the_cron_reach_the_devices_that_did_not_get_it(): void
    {
        $announcement = Announcement::factory()->create(['push_phone' => true]);
        $announcement->forceFill(['push_finished_at' => now()])->save();

        // Saving other changes with the box still ticked does not start again.
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement])
            ->set('title', 'Nuevo título')
            ->call('save');
        $this->assertNotNull($announcement->fresh()->push_finished_at);

        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement->fresh()])
            ->set('pushPhone', false)
            ->call('save');
        Livewire::actingAs($this->admin)->test(AnnouncementEditor::class, ['announcement' => $announcement->fresh()])
            ->set('pushPhone', true)
            ->call('save');

        $this->assertNull($announcement->fresh()->push_finished_at);
    }
}
