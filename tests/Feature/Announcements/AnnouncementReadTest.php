<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mandatory reading (odd/tasks/inicio-fase-2.md WU1): a mandatory
 * announcement asks each person who sees it to confirm they read it,
 * once, with a plain POST (the home is plain HTML and may be a saved copy).
 */
class AnnouncementReadTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados']);
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana Pérez']);
    }

    // --- Model ------------------------------------------------------------

    public function test_announcements_are_not_mandatory_by_default(): void
    {
        $this->assertFalse(Announcement::factory()->create()->fresh()->requires_read);
        $this->assertTrue(Announcement::factory()->mandatory()->create()->fresh()->requires_read);
    }

    public function test_a_person_reads_an_announcement_once(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        $first = $announcement->markReadBy($this->ana);
        $this->travel(2)->hours();
        $again = $announcement->markReadBy($this->ana);

        $this->assertSame(1, AnnouncementRead::query()->count());
        $this->assertTrue($first->is($again));
        $this->assertTrue($again->read_at->equalTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota')));
    }

    public function test_reads_go_away_with_the_person_or_the_announcement(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $luis = User::factory()->microsoft($this->segurex)->create();
        $announcement->markReadBy($this->ana);
        $announcement->markReadBy($luis);

        $luis->delete();
        $this->assertSame(1, AnnouncementRead::query()->count());

        $announcement->delete();
        $this->assertSame(0, AnnouncementRead::query()->count());
    }

    // --- /anuncios --------------------------------------------------------

    public function test_the_list_asks_to_confirm_a_mandatory_announcement(): void
    {
        $mandatory = Announcement::factory()->mandatory()->create(['title' => 'Nueva política de contraseñas']);
        Announcement::factory()->create(['title' => 'Almuerzo de fin de año']);

        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertOk()
            ->assertSeeText('Lectura obligatoria')
            ->assertSee('action="'.route('announcements.read', $mandatory).'"', false)
            ->assertSee('name="_token"', false)
            ->assertSeeText('Confirmar lectura')
            ->assertSee('id="announcement-'.$mandatory->id.'-title"', false)
            ->assertSee('aria-describedby="announcement-'.$mandatory->id.'-title"', false)
            ->assertDontSeeText('Leído el');

        $this->assertSame(1, substr_count(
            $this->actingAs($this->ana)->get(route('announcements.index'))->getContent(),
            'Confirmar lectura',
        ));
    }

    public function test_confirming_records_one_read_and_shows_the_date(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))
            ->assertRedirect(route('announcements.show', $announcement));
        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))
            ->assertRedirect(route('announcements.show', $announcement));

        $this->assertSame(1, AnnouncementRead::query()->where('announcement_id', $announcement->id)->where('user_id', $this->ana->id)->count());

        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertSeeText('Lectura obligatoria')
            ->assertSeeText('Leído el 29 de octubre')
            ->assertDontSeeText('Confirmar lectura');
    }

    public function test_another_persons_read_does_not_count_for_me(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();
        $announcement->markReadBy(User::factory()->microsoft($this->segurex)->create());

        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertSeeText('Confirmar lectura')
            ->assertDontSeeText('Leído el');
    }

    public function test_announcements_one_could_never_see_are_not_found(): void
    {
        $never = [
            'other company' => Announcement::factory()->mandatory()->forCompanies($this->derivados)->create(),
            'other company, withdrawn' => Announcement::factory()->mandatory()->withdrawn()->forCompanies($this->derivados)->create(),
            'other company, expired' => Announcement::factory()->mandatory()->expired()->forCompanies($this->derivados)->create(),
            'other company, not mandatory' => Announcement::factory()->forCompanies($this->derivados)->create(),
            'scheduled' => Announcement::factory()->mandatory()->scheduled()->create(),
        ];

        foreach ($never as $case => $announcement) {
            $this->actingAs($this->ana)->post(route('announcements.read', $announcement))->assertNotFound();
            $this->actingAs($this->ana)->post(route('announcements.read', $announcement), ['from' => 'home'])->assertNotFound();
        }

        $this->actingAs($this->ana)->post('/anuncios/999999/leido')->assertNotFound();
        $this->assertSame(0, AnnouncementRead::query()->count());
    }

    public function test_a_stale_button_goes_back_with_a_message_and_records_nothing(): void
    {
        // Shown to Ana's company once, no longer asking to confirm (a saved
        // copy of the home, or a page left open).
        $stale = [
            'withdrawn' => Announcement::factory()->mandatory()->withdrawn()->create(),
            'expired' => Announcement::factory()->mandatory()->expired()->forCompanies($this->segurex)->create(),
            'not mandatory any more' => Announcement::factory()->create(),
        ];

        foreach ($stale as $case => $announcement) {
            $this->actingAs($this->ana)->post(route('announcements.read', $announcement))
                ->assertRedirect(route('announcements.index'))
                ->assertSessionHas('status', 'Este anuncio ya no pide confirmación.');

            $this->actingAs($this->ana)->post(route('announcements.read', $announcement), ['from' => 'home'])
                ->assertRedirect(route('home').'#anuncios')
                ->assertSessionHas('status', 'Este anuncio ya no pide confirmación.');
        }

        $this->assertSame(0, AnnouncementRead::query()->count());

        $this->followingRedirects()->actingAs($this->ana)
            ->post(route('announcements.read', $stale['withdrawn']), ['from' => 'home'])
            ->assertOk()
            ->assertSeeText('Este anuncio ya no pide confirmación.');
    }

    public function test_a_read_left_from_before_is_kept_if_another_request_wrote_it_first(): void
    {
        // Two taps at once: the other request inserted the row between this
        // one's start and its write. createOrFirst() writes first and falls
        // back to the existing row on the unique pair, so there is no gap
        // between a check and a write to race through.
        $announcement = Announcement::factory()->mandatory()->create();
        DB::table('announcement_reads')->insert([
            'announcement_id' => $announcement->id,
            'user_id' => $this->ana->id,
            'read_at' => '2026-10-29 09:00:00',
        ]);

        $this->travel(1)->hours();
        $read = $announcement->markReadBy($this->ana);

        $this->assertSame(1, AnnouncementRead::query()->count());
        $this->assertSame('2026-10-29 09:00', $read->read_at->format('Y-m-d H:i'));
    }

    // --- Limits -----------------------------------------------------------

    public function test_confirming_has_its_own_limit(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        // Another route's budget (throttle:60,1, one counter per account)
        // is half spent: confirming must not share it.
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->ana)->get(route('announcements.show', $announcement))->assertRedirect();
        }

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))->assertRedirect();

        for ($i = 1; $i < 30; $i++) {
            $this->actingAs($this->ana)->post(route('announcements.read', $announcement))->assertRedirect();
        }

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))->assertTooManyRequests();
        $this->actingAs($this->ana)->get(route('announcements.show', $announcement))->assertRedirect();
    }

    public function test_people_without_a_company_cannot_confirm(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        $this->actingAs(User::factory()->emergency()->create())->post(route('announcements.read', $announcement))->assertNotFound();
        $this->actingAs(User::factory()->create(['company_id' => null]))->post(route('announcements.read', $announcement))->assertNotFound();

        $this->assertSame(0, AnnouncementRead::query()->count());
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        $this->post(route('announcements.read', $announcement))->assertRedirect(route('login'));
        $this->assertSame(0, AnnouncementRead::query()->count());
    }

    public function test_confirming_needs_the_csrf_token(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        // The testing kernel skips CSRF; check it as a real request would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement))->assertStatus(419);
        $this->actingAs($this->ana)->get(route('announcements.read', $announcement))->assertMethodNotAllowed();
        $this->assertSame(0, AnnouncementRead::query()->count());
    }

    // --- Home -------------------------------------------------------------

    public function test_the_home_asks_to_confirm_and_comes_back_after(): void
    {
        $announcement = Announcement::factory()->mandatory()->create(['title' => 'Nueva política de contraseñas']);

        $this->actingAs($this->ana)->get('/')
            ->assertOk()
            ->assertSee('action="'.route('announcements.read', $announcement).'"', false)
            ->assertSee('<input type="hidden" name="from" value="home">', false)
            ->assertSeeText('Lectura obligatoria');

        $this->actingAs($this->ana)->post(route('announcements.read', $announcement), ['from' => 'home'])
            ->assertRedirect(route('home').'#anuncios');

        $this->actingAs($this->ana)->get('/')
            ->assertSeeText('Leído el 29 de octubre')
            ->assertDontSeeText('Confirmar lectura')
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_the_phone_banner_says_the_announcement_is_mandatory_until_read(): void
    {
        $announcement = Announcement::factory()->mandatory()->create();

        $banner = fn () => $this->bannerOf($this->actingAs($this->ana)->get('/')->getContent());

        $this->assertStringContainsString('Lectura obligatoria', $banner());

        $announcement->markReadBy($this->ana);
        $this->assertStringNotContainsString('Lectura obligatoria', $banner());
    }

    public function test_the_read_state_costs_the_home_no_extra_query(): void
    {
        $announcements = Announcement::factory()->count(3)->create();
        $this->homeQueries(); // Warms the settings cache, as on any later request.
        $plain = $this->homeQueries();

        $announcements->each->update(['requires_read' => true]);
        $announcements[0]->markReadBy($this->ana);

        $mandatory = $this->homeQueries();

        $this->assertSame($plain, $mandatory);
        $this->assertLessThanOrEqual(12, $mandatory);
    }

    private function bannerOf(string $html): string
    {
        $this->assertSame(1, preg_match('/<[^>]*data-announcement-strip.*?<\/(a|div)>\s*<\/div>/s', $html, $match));

        return $match[0];
    }

    private function homeQueries(): int
    {
        $user = $this->ana->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }
}
