<?php

namespace Tests\Feature\Catalog;

use App\Catalog\AppCatalog;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Todas las empresas activas» (brief §5.2): an app flagged
 * `available_to_all_companies` counts as linked to every active company,
 * including companies created later. Access rules still decide who sees it.
 */
class AllCompaniesFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_flag_defaults_to_off(): void
    {
        $app = App::factory()->create();

        $this->assertFalse($app->fresh()->available_to_all_companies);
    }

    public function test_a_flagged_app_reaches_an_existing_company_without_a_link(): void
    {
        $company = Company::factory()->create();
        $app = App::factory()->create(['available_to_all_companies' => true]);
        AppAccessRule::forCompany($app, $company);

        $this->assertVisible($app, User::factory()->microsoft($company)->create());
    }

    public function test_a_flagged_app_reaches_a_company_created_afterwards(): void
    {
        $app = App::factory()->create(['available_to_all_companies' => true]);
        $newcomer = Company::factory()->create();
        AppAccessRule::forCompany($app, $newcomer);

        $this->assertVisible($app, User::factory()->microsoft($newcomer)->create());
    }

    public function test_without_the_flag_the_same_rule_grants_nothing(): void
    {
        $company = Company::factory()->create();
        $app = App::factory()->create();
        AppAccessRule::forCompany($app, $company);

        $this->assertNotVisible($app, User::factory()->microsoft($company)->create());
    }

    public function test_access_rules_still_apply_to_flagged_apps(): void
    {
        $company = Company::factory()->create();
        $app = App::factory()->create(['available_to_all_companies' => true]);
        AppAccessRule::forGroup($app, $company, 'APP-Cartera');

        $this->assertNotVisible($app, User::factory()->microsoft($company)->create());
    }

    public function test_inactive_companies_get_nothing_even_when_flagged(): void
    {
        $company = Company::factory()->inactive()->create();
        $app = App::factory()->create(['available_to_all_companies' => true]);
        AppAccessRule::forCompany($app, $company);

        $this->assertNotVisible($app, User::factory()->microsoft($company)->create());
    }

    public function test_hidden_flagged_apps_stay_hidden(): void
    {
        $company = Company::factory()->create();
        $app = App::factory()->hidden()->create(['available_to_all_companies' => true]);
        AppAccessRule::forCompany($app, $company);

        $this->assertNotVisible($app, User::factory()->microsoft($company)->create());
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
