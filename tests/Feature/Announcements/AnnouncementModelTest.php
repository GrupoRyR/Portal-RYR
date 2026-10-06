<?php

namespace Tests\Feature\Announcements;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AnnouncementModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(10, 0));

        $this->segurex = Company::factory()->create(['short_name' => 'Segurex']);
        $this->derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();
        $this->luis = User::factory()->microsoft($this->derivados)->create();
    }

    public function test_an_announcement_for_all_companies_is_visible_to_everyone_with_a_company(): void
    {
        $announcement = Announcement::factory()->create();

        $this->assertVisible($announcement, $this->ana);
        $this->assertVisible($announcement, $this->luis);
    }

    public function test_an_announcement_for_one_company_is_visible_only_to_that_company(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->derivados)->create();

        $this->assertVisible($announcement, $this->luis);
        $this->assertNotVisible($announcement, $this->ana);
    }

    public function test_an_announcement_for_several_companies_reaches_each_of_them(): void
    {
        $announcement = Announcement::factory()->forCompanies($this->segurex, $this->derivados)->create();

        $this->assertVisible($announcement, $this->ana);
        $this->assertVisible($announcement, $this->luis);
    }

    public function test_it_is_hidden_before_it_starts(): void
    {
        $announcement = Announcement::factory()->create(['starts_at' => now()->addMinute()]);

        $this->assertNotVisible($announcement, $this->ana);

        $this->travel(1)->minutes();
        $this->assertVisible($announcement, $this->ana);
    }

    public function test_it_is_hidden_once_it_ends(): void
    {
        $announcement = Announcement::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMinute()]);

        $this->assertVisible($announcement, $this->ana);

        $this->travel(1)->minutes();
        $this->assertNotVisible($announcement, $this->ana);
    }

    public function test_a_withdrawn_announcement_is_hidden(): void
    {
        $announcement = Announcement::factory()->withdrawn()->create();

        $this->assertNotVisible($announcement, $this->ana);
    }

    public function test_users_without_a_company_see_no_announcement(): void
    {
        Announcement::factory()->create();
        $emergency = User::factory()->emergency()->create();
        $plain = User::factory()->create();

        $this->assertSame(0, Announcement::query()->visibleTo($emergency)->count());
        $this->assertSame(0, Announcement::query()->visibleTo($plain)->count());
    }

    public function test_for_company_includes_announcements_for_all_companies(): void
    {
        $all = Announcement::factory()->create();
        $mine = Announcement::factory()->forCompanies($this->segurex)->create();
        Announcement::factory()->forCompanies($this->derivados)->create();

        $this->assertEqualsCanonicalizing(
            [$all->id, $mine->id],
            Announcement::query()->forCompany($this->segurex)->pluck('id')->all(),
        );
    }

    public function test_pinned_come_first_then_the_newest(): void
    {
        $old = Announcement::factory()->create(['starts_at' => now()->subDays(5)]);
        $pinnedOld = Announcement::factory()->pinned()->create(['starts_at' => now()->subDays(9)]);
        $new = Announcement::factory()->create(['starts_at' => now()->subHour()]);
        $pinnedNew = Announcement::factory()->pinned()->create(['starts_at' => now()->subDays(2)]);

        $this->assertSame(
            [$pinnedNew->id, $pinnedOld->id, $new->id, $old->id],
            Announcement::query()->visibleTo($this->ana)->ordered()->pluck('id')->all(),
        );
    }

    public function test_the_status_follows_the_window_and_the_withdrawal(): void
    {
        $this->assertSame(Announcement::STATUS_PUBLISHED, Announcement::factory()->create()->status);
        $this->assertSame(Announcement::STATUS_SCHEDULED, Announcement::factory()->scheduled()->create()->status);
        $this->assertSame(Announcement::STATUS_EXPIRED, Announcement::factory()->expired()->create()->status);
        $this->assertSame(Announcement::STATUS_WITHDRAWN, Announcement::factory()->scheduled()->withdrawn()->create()->status);

        $this->assertSame(['programado', 'publicado', 'vencido', 'retirado'], [
            Announcement::STATUS_SCHEDULED,
            Announcement::STATUS_PUBLISHED,
            Announcement::STATUS_EXPIRED,
            Announcement::STATUS_WITHDRAWN,
        ]);
    }

    public function test_status_scopes_match_the_status_accessor(): void
    {
        Announcement::factory()->create();
        Announcement::factory()->create(['ends_at' => now()->addDay()]);
        Announcement::factory()->scheduled()->create();
        Announcement::factory()->expired()->create();
        Announcement::factory()->withdrawn()->create();

        foreach (Announcement::STATUSES as $status) {
            $ids = Announcement::query()->inStatus($status)->pluck('id')->all();

            $this->assertEqualsCanonicalizing(
                Announcement::all()->filter(fn (Announcement $a) => $a->status === $status)->values()->modelKeys(),
                $ids,
                $status,
            );
        }
    }

    public function test_changes_are_logged_with_their_causer(): void
    {
        $this->actingAs($this->ana);

        $announcement = Announcement::factory()->create(['title' => 'Primero']);
        $announcement->update(['title' => 'Segundo']);
        $announcement->withdraw();
        $announcement->republish();

        $entries = Activity::query()->where('log_name', Announcement::AUDIT_LOG)->orderBy('id')->get();

        $this->assertSame(
            ['created', 'updated', 'announcement.withdrawn', 'announcement.republished'],
            $entries->pluck('event')->all(),
        );
        $this->assertSame(['title' => 'Segundo'], $entries[1]->properties['attributes']);
        $this->assertSame(['title' => 'Primero'], $entries[1]->properties['old']);
        $this->assertTrue($entries->every(fn (Activity $entry) => $entry->causer?->is($this->ana)));
        $this->assertTrue($entries->every(fn (Activity $entry) => $entry->subject?->is($announcement)));
        $this->assertNull($announcement->fresh()->withdrawn_at);
    }

    public function test_withdrawing_twice_logs_once(): void
    {
        $announcement = Announcement::factory()->create();

        $announcement->withdraw();
        $announcement->withdraw();

        $this->assertSame(1, Activity::query()->where('event', 'announcement.withdrawn')->count());
    }

    private function assertVisible(Announcement $announcement, User $user): void
    {
        $this->assertTrue(
            Announcement::query()->visibleTo($user->fresh())->whereKey($announcement->id)->exists(),
            'Expected the announcement to be visible.',
        );
    }

    private function assertNotVisible(Announcement $announcement, User $user): void
    {
        $this->assertFalse(
            Announcement::query()->visibleTo($user->fresh())->whereKey($announcement->id)->exists(),
            'Expected the announcement to be hidden.',
        );
    }
}
