<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditPresenter;
use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use App\Models\WeeklyDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * «Resumen de los lunes» in Admin › Empresas (odd/tasks/inicio-fase-2.md
 * WU3): a switch beside the list fields, off by default and audited, and
 * how the last digest went. No manual resend.
 */
class CompanyWeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'America/Bogota'));

        $this->company = Company::factory()
            ->withListEmail('todos@segurex.com', 'segurex.info@segurex.com')
            ->create([
                'name' => 'Segurex Latam',
                'short_name' => 'Segurex',
                'domains' => ['segurex.com'],
                'color_primary' => '#FF671F',
                'color_accent' => '#666666',
                'color_dark' => '#8F360B',
            ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(CompanyEditor::class, ['company' => $this->company->fresh()]);
    }

    public function test_the_switch_is_off_by_default_and_saving_it_is_audited(): void
    {
        $this->editor()
            ->assertSeeText('Resumen de los lunes')
            ->assertSet('weeklyDigest', false)
            ->set('weeklyDigest', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($this->company->fresh()->weekly_digest_enabled);

        $activity = Activity::query()->where('log_name', Company::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertContains(['label' => 'Resumen de los lunes', 'old' => 'No', 'new' => 'Sí'], $entry->changes);
    }

    public function test_it_loads_the_saved_switch_and_turns_it_off(): void
    {
        $this->company->update(['weekly_digest_enabled' => true]);

        $this->editor()
            ->assertSet('weeklyDigest', true)
            ->set('weeklyDigest', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->company->fresh()->weekly_digest_enabled);
    }

    public function test_the_help_says_it_needs_the_list_and_the_sender(): void
    {
        $this->editor()
            ->assertSeeText('Necesita la lista de todo el personal y el buzón remitente');
    }

    public function test_without_a_digest_yet_it_says_so(): void
    {
        $this->editor()->assertSeeText('Aún no se ha enviado.');
    }

    public function test_it_shows_how_the_last_digest_went(): void
    {
        $cases = [
            [['status' => WeeklyDigest::STATUS_SENT, 'sent_at' => CarbonImmutable::parse('2026-11-09 07:00:12', 'America/Bogota')], 'Enviado el lunes 9 de noviembre, 07:00.'],
            [['status' => WeeklyDigest::STATUS_EMPTY], 'Sin contenido: la semana del 9 de noviembre no tenía nada que contar y no se envió.'],
            [['status' => WeeklyDigest::STATUS_SENDING], 'Enviando…'],
            [['status' => WeeklyDigest::STATUS_FAILED, 'error_code' => 'Interrupted'], 'Interrumpido: el envío del lunes 9 de noviembre se cortó y no se sabe si salió.'],
            [['status' => WeeklyDigest::STATUS_FAILED, 'error_code' => 'ErrorAccessDenied'], 'No se pudo enviar (ErrorAccessDenied).'],
        ];

        foreach ($cases as [$attributes, $text]) {
            WeeklyDigest::query()->delete();
            WeeklyDigest::query()->create(['company_id' => $this->company->id, 'week_start' => '2026-11-09', 'attempted_at' => now(), ...$attributes]);

            $this->editor()->assertSeeText($text);
        }

        // The Spanish hint of the list emails, by code.
        $this->editor()->assertSeeText('El buzón remitente no tiene permiso de envío.');
    }

    public function test_a_row_left_sending_by_a_dead_run_shows_as_interrupted_before_the_next_run(): void
    {
        WeeklyDigest::query()->create(['company_id' => $this->company->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_SENDING, 'attempted_at' => now()]);

        $this->travel(20)->minutes();

        $this->editor()
            ->assertSeeText('Interrumpido: el envío del lunes 9 de noviembre se cortó y no se sabe si salió.')
            ->assertDontSeeText('Enviando…');
    }

    public function test_it_shows_the_latest_week(): void
    {
        WeeklyDigest::query()->create(['company_id' => $this->company->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_EMPTY]);
        WeeklyDigest::query()->create(['company_id' => $this->company->id, 'week_start' => '2026-11-02', 'status' => WeeklyDigest::STATUS_FAILED, 'error_code' => 'HTTP 503']);

        $this->editor()
            ->assertSeeText('Sin contenido')
            ->assertDontSeeText('HTTP 503');
    }

    public function test_there_is_no_manual_resend(): void
    {
        WeeklyDigest::query()->create(['company_id' => $this->company->id, 'week_start' => '2026-11-09', 'status' => WeeklyDigest::STATUS_FAILED, 'error_code' => 'ErrorAccessDenied']);

        $this->editor()
            ->assertSeeText('El resumen no se reenvía: el siguiente sale el próximo lunes.')
            ->assertDontSeeText('Reintentar');
    }

    public function test_only_the_super_admin_manages_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('portal_admin');

        $this->editor($admin)->assertForbidden();
    }
}
