<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The markup the logo animations hang on (resources/css/app.css): one
 * container per logo, whatever it holds, the status on the card and the
 * entrance flag on a plain page load only.
 */
class AnimatedLogosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    public function test_uploaded_icons_and_initials_share_one_logo_container(): void
    {
        $this->app(['name' => 'Cartera', 'icon_path' => 'icons/apps/cartera.svg']);
        $this->app(['name' => 'Recursos humanos', 'icon_path' => null]);

        $html = $this->home()->getContent();

        $this->assertMatchesRegularExpression('/<picture class="app-logo [^"]*">\s*<img src="[^"]*cartera\.svg"/', $html);
        $this->assertMatchesRegularExpression('/<span aria-hidden="true" class="app-logo [^"]*"[^>]*>R<\/span>/', $html);
    }

    public function test_each_card_carries_its_status_for_the_logo(): void
    {
        $this->app(['status' => App::STATUS_MAINTENANCE]);
        $failing = $this->app(['login_type' => 'microsoft']);
        $failing->forceFill(['external_status_at' => now(), 'external_status' => 'down'])->save();

        $this->home()
            ->assertSee('data-status="maintenance"', false)
            ->assertSee('data-status="failing"', false);
    }

    public function test_logos_enter_on_a_plain_page_load(): void
    {
        $this->app(['name' => 'Cartera']);

        $this->home()->assertSee('data-enter', false);
    }

    public function test_a_server_search_does_not_replay_the_entrance(): void
    {
        $this->app(['name' => 'Cartera']);

        $this->home('?q=cartera')
            ->assertSeeText('Cartera')
            ->assertDontSee('data-enter', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function app(array $attributes): App
    {
        $app = App::factory()->forCompanies($this->company)->create($attributes);
        AppAccessRule::forCompany($app, $this->company);

        return $app;
    }

    private function home(string $query = ''): TestResponse
    {
        return $this->actingAs(User::factory()->microsoft($this->company)->create())->get('/'.$query)->assertOk();
    }
}
