<?php

namespace Tests\Feature\Catalog;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentAppsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());
        $this->company = Company::factory()->create();
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    public function test_recent_apps_are_distinct_and_newest_first(): void
    {
        [$a, $b, $c] = $this->openableApps(3);
        $this->launch($a, 50);
        $this->launch($b, 40);
        $this->launch($a, 30);
        $this->launch($c, 20);
        $this->launch($b, 10);

        $this->assertSame([$b->id, $c->id, $a->id], $this->recentIds());
    }

    public function test_recent_apps_respect_the_limit(): void
    {
        $apps = $this->openableApps(7);
        foreach ($apps as $i => $app) {
            $this->launch($app, 100 - $i);
        }

        $this->assertCount(5, app(AppCatalog::class)->recentApps($this->user));
        $this->assertSame([$apps[6]->id, $apps[5]->id], $this->recentIds(2));
    }

    public function test_launches_older_than_thirty_days_are_ignored(): void
    {
        [$a, $b] = $this->openableApps(2);
        $this->launch($a, 60 * 24 * 30 - 1);
        $this->launch($b, 60 * 24 * 30 + 1);

        $this->assertSame([$a->id], $this->recentIds());
    }

    public function test_apps_no_longer_visible_are_left_out(): void
    {
        [$a, $b, $c] = $this->openableApps(3);
        $this->launch($a, 3);
        $this->launch($b, 2);
        $this->launch($c, 1);
        $b->update(['status' => App::STATUS_HIDDEN]);
        $c->accessRules()->delete();

        $this->assertSame([$a->id], $this->recentIds());
    }

    public function test_only_the_users_own_launches_count(): void
    {
        [$a] = $this->openableApps(1);
        $other = User::factory()->microsoft($this->company)->create();
        AppLaunch::create(['user_id' => $other->id, 'app_id' => $a->id, 'company_id' => $this->company->id, 'device' => 'desktop', 'launched_at' => now()]);

        $this->assertSame([], $this->recentIds());
    }

    public function test_opening_an_app_makes_it_recent(): void
    {
        [$a] = $this->openableApps(1);

        $this->actingAs($this->user)->get("/apps/{$a->slug}")->assertRedirect();

        $this->assertSame([$a->id], $this->recentIds());
    }

    public function test_last_opened_within_returns_the_latest_app_in_the_window(): void
    {
        [$a, $b] = $this->openableApps(2);
        $catalog = app(AppCatalog::class);

        $this->assertNull($catalog->lastOpenedWithin($this->user));

        $this->launch($a, 45);
        $this->assertNull($catalog->lastOpenedWithin($this->user));
        $this->assertTrue($catalog->lastOpenedWithin($this->user, 60)->is($a));

        $this->launch($b, 29);
        $this->launch($a, 40);
        $this->assertTrue($catalog->lastOpenedWithin($this->user)->is($b));
    }

    public function test_last_opened_within_skips_apps_no_longer_visible(): void
    {
        [$a, $b] = $this->openableApps(2);
        $this->launch($a, 10);
        $this->launch($b, 5);
        $b->update(['status' => App::STATUS_HIDDEN]);

        $this->assertTrue(app(AppCatalog::class)->lastOpenedWithin($this->user)->is($a));
    }

    /**
     * @return list<App>
     */
    private function openableApps(int $count): array
    {
        return App::factory()->count($count)->forCompanies($this->company)->create()
            ->each(fn (App $app) => AppAccessRule::forCompany($app, $this->company))
            ->all();
    }

    private function launch(App $app, int $minutesAgo): void
    {
        AppLaunch::create([
            'user_id' => $this->user->id,
            'app_id' => $app->id,
            'company_id' => $this->company->id,
            'device' => 'desktop',
            'launched_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @return list<int>
     */
    private function recentIds(int $limit = 5): array
    {
        return app(AppCatalog::class)->recentApps($this->user->fresh(), $limit)->pluck('id')->all();
    }
}
