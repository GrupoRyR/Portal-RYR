<?php

namespace Tests\Feature\Backups;

use App\Audit\AuditPresenter;
use App\Backups\BackupRequests;
use App\Livewire\Admin\SettingsEditor;
use App\Models\BackupRun;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Respaldo diario» card of Configuración: real data from the backups
 * disk and the recorded runs, and «Respaldar ahora», which only leaves a
 * request for the scheduler (never a dump inside the web request).
 */
class BackupNowTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));
        Storage::fake('backups');
        RateLimiter::clear(BackupRequests::RATE_LIMIT_KEY);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->admin = $this->userWith('super_admin', ['name' => 'Jhon Castro']);
    }

    private function userWith(string $role, array $attributes = []): User
    {
        $user = User::factory()->microsoft($this->segurex)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function putBackup(string $name, int $bytes = 1024): void
    {
        Storage::disk('backups')->put("portal-ryr/{$name}.zip", str_repeat('x', $bytes));
    }

    private function recordRun(string $status, string $finishedAt, ?User $requestedBy = null): BackupRun
    {
        return BackupRun::query()->create([
            'status' => $status,
            'requested_by' => $requestedBy?->id,
            'requested_at' => $requestedBy ? CarbonImmutable::parse($finishedAt)->subMinutes(2) : null,
            'started_at' => CarbonImmutable::parse($finishedAt)->subMinute(),
            'finished_at' => $finishedAt,
        ]);
    }

    // --- Card ---------------------------------------------------------------

    public function test_the_card_says_never_when_there_is_no_backup(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeText('Respaldo diario de la base de datos')
            ->assertSeeText('Último respaldo: nunca')
            ->assertSeeText('Respaldar ahora')
            ->assertDontSeeText('Próximamente')
            ->assertDontSeeText('correcto');
    }

    public function test_the_card_shows_the_newest_backup_its_size_and_how_many_are_kept(): void
    {
        $this->putBackup('2026-10-04-02-30-00', 1024);
        $this->putBackup('2026-10-05-02-30-00', 1024);
        $this->putBackup('2026-10-06-02-30-01', 3 * 1024 * 1024 + 300 * 1024);
        Storage::disk('backups')->put('portal-ryr/notes.txt', 'not a backup');
        $this->recordRun(BackupRun::STATUS_SUCCEEDED, '2026-10-06 02:31:10');

        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeText('Último respaldo: 6 oct. 2026, 02:30 · correcto')
            ->assertSeeText('3,3 MB · 3 copias guardadas en el servidor');
    }

    public function test_a_failed_last_attempt_is_reported_without_hiding_the_last_good_copy(): void
    {
        $this->putBackup('2026-10-05-02-30-00');
        $this->recordRun(BackupRun::STATUS_SUCCEEDED, '2026-10-05 02:31:00');
        $this->recordRun(BackupRun::STATUS_FAILED, '2026-10-06 02:30:20');

        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSeeText('Último respaldo: 5 oct. 2026, 02:30')
            ->assertSeeText('El intento del 6 oct. 2026, 02:30 terminó con error.')
            ->assertSeeText('1 copia guardada en el servidor')
            ->assertDontSeeText('· correcto');
    }

    public function test_a_failure_with_no_copy_at_all_still_says_never(): void
    {
        $this->recordRun(BackupRun::STATUS_FAILED, '2026-10-06 02:30:20');

        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertSeeText('Último respaldo: nunca')
            ->assertSeeText('El intento del 6 oct. 2026, 02:30 terminó con error.');
    }

    // --- Respaldar ahora ----------------------------------------------------

    public function test_backup_now_leaves_a_request_for_the_scheduler_and_audits_it(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('requestBackup')
            ->assertHasNoErrors()
            ->assertSee('Programado: se hará en el próximo minuto.')
            ->assertSeeHtml('disabled');

        $run = BackupRun::query()->sole();
        $this->assertSame(BackupRun::STATUS_PENDING, $run->status);
        $this->assertTrue($run->requestedBy->is($this->admin));
        $this->assertSame('2026-10-06 10:00:00', $run->requested_at->timezone('America/Bogota')->format('Y-m-d H:i:s'));
        $this->assertNull($run->started_at);
        $this->assertSame([], Storage::disk('backups')->allFiles(), 'No backup may run inside the web request.');

        $activity = Activity::query()->where('log_name', BackupRequests::AUDIT_LOG)->sole();
        $this->assertSame('backup.requested', $activity->event);
        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertSame('Pidió un respaldo inmediato de la base de datos', app(AuditPresenter::class)->activity($activity)->what);
    }

    public function test_a_second_request_while_one_is_pending_is_refused(): void
    {
        $component = Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('requestBackup');

        $component->call('requestBackup')->assertHasErrors('backup');

        $this->assertSame(1, BackupRun::query()->count());
        $this->assertSame(1, Activity::query()->where('log_name', BackupRequests::AUDIT_LOG)->count());
    }

    public function test_a_running_backup_shows_in_progress(): void
    {
        BackupRun::query()->create([
            'status' => BackupRun::STATUS_RUNNING,
            'requested_by' => $this->admin->id,
            'requested_at' => now()->subMinutes(2),
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.settings.index'))
            ->assertSeeText('En curso: el respaldo se está haciendo ahora.');
    }

    public function test_backup_now_is_rate_limited(): void
    {
        foreach (range(1, BackupRequests::MAX_REQUESTS_PER_HOUR) as $attempt) {
            Livewire::actingAs($this->admin)->test(SettingsEditor::class)->call('requestBackup')->assertHasNoErrors();
            BackupRun::query()->update(['status' => BackupRun::STATUS_SUCCEEDED, 'finished_at' => now()]);
        }

        Livewire::actingAs($this->admin)->test(SettingsEditor::class)
            ->call('requestBackup')
            ->assertHasErrors('backup');

        $this->assertSame(BackupRequests::MAX_REQUESTS_PER_HOUR, BackupRun::query()->count());
        $this->assertSame(0, BackupRun::query()->where('status', BackupRun::STATUS_PENDING)->count());
    }

    public function test_only_super_admins_can_request_a_backup_even_with_crafted_calls(): void
    {
        foreach (['portal_admin', 'announcements_editor'] as $role) {
            $user = $this->userWith($role);

            $component = Livewire::actingAs($this->admin)->test(SettingsEditor::class);
            $this->actingAs($user);
            $component->call('requestBackup')->assertForbidden();

            $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
        }

        $this->assertSame(0, BackupRun::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', BackupRequests::AUDIT_LOG)->count());
    }
}
