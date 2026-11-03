<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /anuncios/{announcement}: a stable link to one announcement (the
 * «Ctrl K» search, odd/tasks/inicio-fase-1.md WU4). It finds the
 * announcement's page of /anuncios when it is followed, so a link from a
 * saved copy of the home page still lands on the right page.
 */
class AnnouncementLinkTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
    }

    public function test_it_redirects_to_the_announcement_on_the_first_page(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->ana)->get(route('announcements.show', $announcement))
            ->assertRedirect(route('announcements.index').'#a-'.$announcement->id);
    }

    public function test_pinned_announcements_push_the_others_to_later_pages(): void
    {
        $newest = $this->unpinned(count: Announcement::PER_PAGE);
        $pinned = Announcement::factory()->pinned()->count(2)->sequence(fn ($sequence) => [
            'title' => 'Fijado '.($sequence->index + 1),
            'starts_at' => now()->subDays(30 + $sequence->index),
        ])->create();

        // Order: 2 pinned (oldest starts), then the 10 unpinned, newest first.
        $this->assertRedirectsToPage($pinned[1], 1);
        $this->assertRedirectsToPage($newest[Announcement::PER_PAGE - 3], 1);
        $this->assertRedirectsToPage($newest[Announcement::PER_PAGE - 2], 2);
        $this->assertRedirectsToPage($newest[Announcement::PER_PAGE - 1], 2);
    }

    public function test_announcements_with_the_same_start_follow_the_list_order(): void
    {
        $same = Announcement::factory()->count(Announcement::PER_PAGE + 1)->create(['starts_at' => now()->subHour()]);

        // Same start: the newest id first, so the first one created is last.
        $this->assertRedirectsToPage($same->first(), 2);
        $this->assertRedirectsToPage($same->last(), 1);
    }

    public function test_following_the_link_lands_on_a_page_holding_the_announcement(): void
    {
        $announcements = $this->unpinned(count: Announcement::PER_PAGE + 3);
        Announcement::factory()->pinned()->create(['starts_at' => now()->subDays(40)]);
        $target = $announcements[Announcement::PER_PAGE - 1];

        $this->actingAs($this->ana)->followingRedirects()
            ->get(route('announcements.show', $target))
            ->assertOk()
            ->assertSee('id="a-'.$target->id.'"', false);
    }

    public function test_announcements_the_viewer_cannot_see_are_not_found(): void
    {
        $hidden = [
            Announcement::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Ajeno']),
            Announcement::factory()->withdrawn()->create(['title' => 'Retirado']),
            Announcement::factory()->scheduled()->create(['title' => 'Programado']),
            Announcement::factory()->expired()->create(['title' => 'Vencido']),
        ];

        foreach ($hidden as $announcement) {
            $this->actingAs($this->ana)->get(route('announcements.show', $announcement))
                ->assertNotFound()
                ->assertDontSeeText($announcement->title);
        }

        $this->actingAs($this->ana)->get('/anuncios/999999')->assertNotFound();
    }

    public function test_viewers_without_a_company_find_none(): void
    {
        $announcement = Announcement::factory()->create();
        $emergency = User::factory()->emergency()->create();

        $this->actingAs($emergency)->get(route('announcements.show', $announcement))->assertNotFound();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $announcement = Announcement::factory()->create();

        $this->get(route('announcements.show', $announcement))->assertRedirect(route('login'));
    }

    private function assertRedirectsToPage(Announcement $announcement, int $page): void
    {
        $this->actingAs($this->ana)->get(route('announcements.show', $announcement))
            ->assertRedirect(route('announcements.index', $page > 1 ? ['page' => $page] : []).'#a-'.$announcement->id);
    }

    /**
     * Unpinned announcements, newest first (index 0 started an hour ago).
     *
     * @return list<Announcement>
     */
    private function unpinned(int $count): array
    {
        return Announcement::factory()->count($count)->sequence(fn ($sequence) => [
            'title' => 'Anuncio '.($sequence->index + 1),
            'starts_at' => now()->subHours($sequence->index + 1),
        ])->create()->all();
    }
}
