<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditEntry;
use App\Audit\AuditPresenter;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría rows of events (odd/tasks/inicio-fase-2.md WU2): a readable
 * subject, the named verbs and Spanish labels; announcements keep theirs.
 */
class EventAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_entries_name_the_event_and_label_its_fields(): void
    {
        $event = Event::factory()->create(['title' => 'Reunión general', 'location' => 'Sala 3']);

        $created = $this->present($this->latest(Event::AUDIT_LOG));
        $this->assertSame('Creó el evento «Reunión general»', $created->what);
        $labels = array_column($created->changes, 'label');
        foreach (['Título', 'Lugar', 'Empieza', 'Termina', 'Todo el día', 'Para quién', 'Se muestra desde', 'Creado por'] as $label) {
            $this->assertContains($label, $labels);
        }
        $this->assertNotContains('Desde', $labels);
        $this->assertNotContains('Hasta', $labels);

        $event->withdraw();
        $this->assertSame('Retiró el evento «Reunión general»', $this->present($this->latest(Event::AUDIT_LOG))->what);

        $event->republish();
        $this->assertSame('Volvió a publicar el evento «Reunión general»', $this->present($this->latest(Event::AUDIT_LOG))->what);

        $event->saveWithCompanies(['audience' => Event::AUDIENCE_COMPANIES], [Company::factory()->create(['short_name' => 'Segurex'])->id]);
        $changed = $this->present($this->latest(Event::AUDIT_LOG));
        $this->assertSame('Cambió las empresas de el evento «Reunión general»', $changed->what);
        $this->assertSame([['label' => 'Empresas', 'old' => '—', 'new' => 'Segurex']], $changed->changes);
    }

    public function test_repeating_every_year_has_its_label(): void
    {
        $event = Event::factory()->allDay('2026-03-15')->create(['title' => 'Aniversario']);
        $event->update(['repeats_yearly' => true]);

        $updated = $this->present($this->latest(Event::AUDIT_LOG));

        $this->assertSame([['label' => 'Se repite cada año', 'old' => 'No', 'new' => 'Sí']], $updated->changes);
    }

    public function test_a_deleted_event_is_named_as_such(): void
    {
        $event = Event::factory()->create(['title' => 'Pausa activa']);
        $activity = $this->latest(Event::AUDIT_LOG);
        $event->companies()->detach();
        Event::query()->whereKey($event->id)->delete();
        $activity->properties = collect();
        $activity->save();

        $this->assertSame('Creó el evento «(eliminado)»', $this->present($activity->fresh())->what);
    }

    public function test_announcements_keep_their_labels(): void
    {
        Announcement::factory()->create(['title' => 'Aviso']);

        $entry = $this->present($this->latest(Announcement::AUDIT_LOG));

        $this->assertSame('Creó el anuncio «Aviso»', $entry->what);
        $labels = array_column($entry->changes, 'label');
        $this->assertContains('Desde', $labels);
        $this->assertNotContains('Empieza', $labels);
        $this->assertNotContains('Termina', $labels);
    }

    private function latest(string $log): Activity
    {
        return Activity::query()->where('log_name', $log)->latest('id')->firstOrFail();
    }

    private function present(Activity $activity): AuditEntry
    {
        return app(AuditPresenter::class)->activity($activity);
    }
}
