<?php

namespace Tests\Feature\Catalog;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex = Company::factory()->create(['slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['slug' => 'derivados']);
    }

    public function test_a_company_rule_shows_the_app_to_everyone_in_the_company(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forCompany($app, $this->segurex);

        $this->assertVisible($app, $this->userOf($this->segurex));
        $this->assertNotVisible($app, $this->userOf($this->derivados));
    }

    public function test_a_user_rule_shows_the_app_to_that_user_only(): void
    {
        $app = $this->appFor($this->segurex);
        $ana = $this->userOf($this->segurex);
        AppAccessRule::forUser($app, $ana);

        $this->assertVisible($app, $ana);
        $this->assertNotVisible($app, $this->userOf($this->segurex));
    }

    public function test_a_group_rule_shows_the_app_to_members_of_the_group(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->assertVisible($app, $this->userOf($this->segurex, ['APP-Cartera']));
        $this->assertNotVisible($app, $this->userOf($this->segurex, ['APP-Otra']));
        $this->assertNotVisible($app, $this->userOf($this->segurex));
    }

    public function test_group_names_match_case_insensitively(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->assertVisible($app, $this->userOf($this->segurex, ['app-cartera']));
        $this->assertVisible($app, $this->userOf($this->segurex, [' APP-CARTERA ']));
    }

    /**
     * Anyone allowed to create a Team (a Microsoft 365 group) can give it
     * any name; only security groups, which IT manages, grant apps.
     */
    public function test_only_security_groups_grant_access(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');
        $team = User::factory()->microsoft($this->segurex)->create();
        $team->syncGroups([['id' => (string) Str::uuid(), 'display_name' => 'APP-Cartera', 'security_group' => false]]);
        $unknown = User::factory()->microsoft($this->segurex)->create();
        $unknown->syncGroups([['id' => (string) Str::uuid(), 'display_name' => 'APP-Cartera']]);

        $this->assertNotVisible($app, $team->fresh());
        $this->assertNotVisible($app, $unknown->fresh());
        $this->assertVisible($app, $this->userOf($this->segurex, ['APP-Cartera']));
    }

    public function test_a_group_that_stops_being_a_security_group_stops_granting(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');
        $user = User::factory()->microsoft($this->segurex)->create();
        $groupId = (string) Str::uuid();

        $user->syncGroups([['id' => $groupId, 'display_name' => 'APP-Cartera', 'security_group' => true]]);
        $this->assertVisible($app, $user->fresh());

        $user->syncGroups([['id' => $groupId, 'display_name' => 'APP-Cartera', 'security_group' => false]]);
        $this->assertNotVisible($app, $user->fresh());
    }

    public function test_a_group_rule_only_applies_to_users_of_its_company(): void
    {
        // Same group name in another tenant: a Derivados user in
        // "APP-Cartera" must not see Segurex's cartera.
        $app = $this->appFor($this->segurex, $this->derivados);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->assertVisible($app, $this->userOf($this->segurex, ['APP-Cartera']));
        $this->assertNotVisible($app, $this->userOf($this->derivados, ['APP-Cartera']));
    }

    public function test_a_rule_does_not_help_when_the_app_is_not_linked_to_the_company(): void
    {
        $app = $this->appFor($this->segurex);
        $user = $this->userOf($this->derivados, ['APP-Cartera']);
        AppAccessRule::forCompany($app, $this->derivados);
        AppAccessRule::forUser($app, $user);
        AppAccessRule::forGroup($app, $this->derivados, 'APP-Cartera');

        $this->assertNotVisible($app, $user);
    }

    public function test_an_app_without_companies_is_shown_to_nobody(): void
    {
        $app = App::factory()->create();
        AppAccessRule::forCompany($app, $this->segurex);

        $this->assertNotVisible($app, $this->userOf($this->segurex));
    }

    public function test_an_app_without_rules_is_shown_to_nobody(): void
    {
        $app = $this->appFor($this->segurex);

        $this->assertNotVisible($app, $this->userOf($this->segurex));
    }

    public function test_hidden_apps_are_excluded(): void
    {
        $app = $this->appFor($this->segurex, state: 'hidden');
        AppAccessRule::forCompany($app, $this->segurex);

        $this->assertNotVisible($app, $this->userOf($this->segurex));
    }

    public function test_apps_in_maintenance_are_included(): void
    {
        $app = $this->appFor($this->segurex, state: 'maintenance');
        AppAccessRule::forCompany($app, $this->segurex);

        $this->assertVisible($app, $this->userOf($this->segurex));
    }

    public function test_users_without_a_company_see_nothing(): void
    {
        $app = $this->appFor($this->segurex);
        $user = User::factory()->create(['company_id' => null]);
        AppAccessRule::create([
            'app_id' => $app->id,
            'type' => AppAccessRule::TYPE_USER,
            'value' => (string) $user->id,
            'company_id' => $this->segurex->id,
        ]);

        $this->assertNotVisible($app, $user);
    }

    public function test_users_of_an_inactive_company_see_nothing(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forCompany($app, $this->segurex);
        $user = $this->userOf($this->segurex);
        $this->segurex->update(['is_active' => false]);

        $this->assertNotVisible($app, $user->fresh());
    }

    public function test_emergency_users_see_nothing(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forCompany($app, $this->segurex);
        $user = User::factory()->emergency()->create();
        $user->forceFill(['company_id' => $this->segurex->id])->save();
        AppAccessRule::forUser($app, $user);

        $this->assertNotVisible($app, $user);
    }

    public function test_visible_apps_come_with_their_category_and_without_duplicates(): void
    {
        $app = $this->appFor($this->segurex);
        $user = $this->userOf($this->segurex, ['APP-Cartera']);
        AppAccessRule::forCompany($app, $this->segurex);
        AppAccessRule::forUser($app, $user);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $apps = app(AppCatalog::class)->visibleApps($user);

        $this->assertCount(1, $apps);
        $this->assertTrue($apps->first()->relationLoaded('category'));
    }

    public function test_resolving_visible_apps_takes_a_constant_number_of_queries(): void
    {
        $user = $this->userOf($this->segurex, ['APP-Uno', 'APP-Dos']);
        $this->makeVisibleApps(2, $user);
        $fresh = $user->fresh();
        $few = $this->countQueries(fn () => app(AppCatalog::class)->visibleApps($fresh)->each(fn (App $app) => $app->category->name));

        $this->makeVisibleApps(12, $user);
        $fresh = $user->fresh();
        $many = $this->countQueries(fn () => app(AppCatalog::class)->visibleApps($fresh)->each(fn (App $app) => $app->category->name));

        $this->assertSame(14, app(AppCatalog::class)->visibleApps($fresh)->count());
        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(5, $many);
    }

    public function test_can_open_follows_the_same_rules(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');
        $member = $this->userOf($this->segurex, ['app-cartera']);
        $stranger = $this->userOf($this->derivados, ['APP-Cartera']);
        $catalog = app(AppCatalog::class);

        $this->assertTrue($catalog->canOpen($member, $app));
        $this->assertFalse($catalog->canOpen($stranger, $app));

        $app->update(['status' => App::STATUS_HIDDEN]);
        $this->assertFalse($catalog->canOpen($member, $app));

        $app->update(['status' => App::STATUS_MAINTENANCE]);
        $this->assertTrue($catalog->canOpen($member, $app));
    }

    public function test_rules_are_unique_per_app_type_value_and_company(): void
    {
        $app = $this->appFor($this->segurex);
        AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');

        $this->expectException(QueryException::class);

        AppAccessRule::create([
            'app_id' => $app->id,
            'type' => AppAccessRule::TYPE_GROUP,
            'value' => 'APP-Cartera',
            'company_id' => $this->segurex->id,
        ]);
    }

    public function test_the_helpers_do_not_duplicate_rules(): void
    {
        $app = $this->appFor($this->segurex);
        $user = $this->userOf($this->segurex);

        foreach (range(1, 2) as $ignored) {
            AppAccessRule::forCompany($app, $this->segurex);
            AppAccessRule::forUser($app, $user);
            AppAccessRule::forGroup($app, $this->segurex, 'APP-Cartera');
        }

        $this->assertSame(3, $app->accessRules()->count());
        $this->assertNull($app->accessRules()->where('type', AppAccessRule::TYPE_COMPANY)->value('value'));
        $this->assertSame((string) $user->id, $app->accessRules()->where('type', AppAccessRule::TYPE_USER)->value('value'));
    }

    private function appFor(Company $company, ?Company $other = null, ?string $state = null): App
    {
        $factory = App::factory();

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        return $factory->forCompanies(...array_filter([$company, $other]))->create();
    }

    /**
     * @param  list<string>  $groups
     */
    private function userOf(Company $company, array $groups = []): User
    {
        $user = User::factory()->microsoft($company)->create();
        $user->syncGroups(array_map(fn (string $name) => ['id' => (string) Str::uuid(), 'display_name' => $name, 'security_group' => true], $groups));

        return $user->fresh();
    }

    private function makeVisibleApps(int $count, User $user): void
    {
        App::factory()->count($count)->forCompanies($this->segurex)->create()
            ->each(function (App $app, int $i) use ($user) {
                match ($i % 3) {
                    0 => AppAccessRule::forCompany($app, $this->segurex),
                    1 => AppAccessRule::forUser($app, $user),
                    2 => AppAccessRule::forGroup($app, $this->segurex, 'APP-Dos'),
                };
            });
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function assertVisible(App $app, User $user): void
    {
        $catalog = app(AppCatalog::class);

        $this->assertContains($app->id, $catalog->visibleApps($user)->pluck('id')->all(), 'App should be visible.');
        $this->assertTrue($catalog->canOpen($user, $app), 'App should open.');
    }

    private function assertNotVisible(App $app, User $user): void
    {
        $catalog = app(AppCatalog::class);

        $this->assertNotContains($app->id, $catalog->visibleApps($user)->pluck('id')->all(), 'App should not be visible.');
        $this->assertFalse($catalog->canOpen($user, $app), 'App should not open.');
    }
}
