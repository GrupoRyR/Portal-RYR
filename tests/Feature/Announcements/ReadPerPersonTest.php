<?php

namespace Tests\Feature\Announcements;

use App\Auth\LinkedAccounts;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mandatory reading counts per person (odd/tasks/ajustes-inicio-y-tareas.md
 * T4): someone with linked accounts in two companies confirms once, and
 * every account of that person shows «Leído el …». Seeing the
 * announcement is still per account.
 */
class ReadPerPersonTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $ana;

    private User $anaDerivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez']);
        $this->anaDerivados = User::factory()->microsoft($this->derivados)->create(['name' => 'Ana Pérez']);

        $person = Person::query()->create();
        foreach ([$this->ana, $this->anaDerivados] as $account) {
            $account->forceFill(['person_id' => $person->id])->save();
        }
    }

    public function test_confirming_with_one_account_marks_it_read_on_the_other(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex, $this->derivados)->create();

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))
            ->assertRedirect(route('announcements.show', $announcement));

        $this->actingAs($this->anaDerivados->fresh())->get(route('announcements.index'))
            ->assertOk()
            ->assertSeeText('Lectura obligatoria')
            ->assertSeeText('Leído el 29 de octubre')
            ->assertDontSeeText('Confirmar lectura');

        $this->actingAs($this->anaDerivados->fresh())->get('/')
            ->assertOk()
            ->assertSeeText('Leído el 29 de octubre')
            ->assertDontSeeText('Confirmar lectura');
    }

    public function test_the_earliest_read_of_the_person_is_shown(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        DB::table('announcement_reads')->insert([
            ['announcement_id' => $announcement->id, 'user_id' => $this->ana->id, 'read_at' => '2026-10-28 15:00:00'],
            ['announcement_id' => $announcement->id, 'user_id' => $this->anaDerivados->id, 'read_at' => '2026-10-27 15:00:00'],
        ]);

        foreach ([$this->ana, $this->anaDerivados] as $account) {
            $this->actingAs($account->fresh())->get(route('announcements.index'))
                ->assertSeeText('Leído el 27 de octubre')
                ->assertDontSeeText('Leído el 28 de octubre');
        }
    }

    public function test_the_other_account_confirming_again_records_nothing(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $announcement->markReadBy($this->ana);

        // A button left on a page or a saved copy of the home.
        $this->actingAs($this->anaDerivados)->post(route('announcements.read', $announcement))
            ->assertRedirect(route('announcements.show', $announcement));
        $this->actingAs($this->anaDerivados)->post(route('announcements.read', $announcement), ['from' => 'home'])
            ->assertRedirect(route('home').'#anuncios');

        $this->assertSame([$this->ana->id], AnnouncementRead::query()->pluck('user_id')->all());
    }

    public function test_an_account_that_is_not_linked_keeps_its_own_state(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $namesake = User::factory()->microsoft($this->derivados)->create(['name' => 'Ana Pérez']);
        $announcement->markReadBy($this->ana);

        $this->actingAs($namesake)->get(route('announcements.index'))
            ->assertSeeText('Confirmar lectura')
            ->assertDontSeeText('Leído el');
    }

    public function test_after_unlinking_each_account_keeps_only_its_own_read(): void
    {
        Mail::fake();
        Notification::fake();
        $announcement = Announcement::factory()->mandatory()->create();
        $announcement->markReadBy($this->ana);

        $this->actingAs($this->anaDerivados->fresh())->get(route('announcements.index'))->assertSeeText('Leído el 29 de octubre');

        $this->assertTrue(app(LinkedAccounts::class)->unlink($this->anaDerivados->fresh(), LinkedAccounts::USER, $this->anaDerivados));

        $this->actingAs($this->anaDerivados->fresh())->get(route('announcements.index'))
            ->assertSeeText('Confirmar lectura')
            ->assertDontSeeText('Leído el');
        $this->actingAs($this->ana->fresh())->get(route('announcements.index'))
            ->assertSeeText('Leído el 29 de octubre');
    }

    public function test_seeing_an_announcement_is_still_per_account(): void
    {
        $announcement = Announcement::factory()->mandatory()->forCompanies($this->segurex)->create(['title' => 'Solo para Segurex']);
        $announcement->markReadBy($this->ana);

        $this->actingAs($this->anaDerivados)->get(route('announcements.index'))
            ->assertOk()
            ->assertDontSeeText('Solo para Segurex');
        $this->actingAs($this->anaDerivados)->post(route('announcements.read', $announcement))->assertNotFound();

        $this->assertSame(1, AnnouncementRead::query()->count());
    }

    public function test_a_read_by_another_account_of_the_person_costs_the_home_no_extra_query(): void
    {
        $announcements = Announcement::factory()->count(3)->forCompanies($this->segurex, $this->derivados)->create();
        $this->homeQueries(); // Warms the settings cache, as on any later request.
        $plain = $this->homeQueries();

        $announcements->each->update(['requires_read' => true]);
        $announcements[0]->markReadBy($this->ana);

        $mandatory = $this->homeQueries();

        $this->assertSame($plain, $mandatory);
        $this->assertLessThanOrEqual(12, $mandatory);
    }

    private function homeQueries(): int
    {
        $user = $this->anaDerivados->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $response = $this->actingAs($user)->get('/')->assertOk();

        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        if (AnnouncementRead::query()->exists()) {
            $response->assertSeeText('Leído el 29 de octubre');
        }

        return $count;
    }
}
