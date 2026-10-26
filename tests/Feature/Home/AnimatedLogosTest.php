<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
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

    public function test_logos_enter_one_after_another_on_a_plain_page_load(): void
    {
        // One app per category: the order must run across the whole page.
        foreach (range(1, 16) as $i) {
            $this->app(['name' => "App {$i}", 'app_category_id' => AppCategory::factory()->create(['sort_order' => $i])->id]);
        }

        preg_match_all('/data-enter style="--logo-order: (\d+)"/', $this->home()->getContent(), $orders);

        // Capped so the last logo starts within a second.
        $this->assertSame([...range(0, 14), 14], array_map('intval', $orders[1]));
    }

    public function test_the_entrance_follows_the_page_order_across_categories(): void
    {
        // Name order (Alfa, Beta, Zeta) differs from page order on purpose.
        $ventas = AppCategory::factory()->create(['name' => 'Ventas', 'sort_order' => 1]);
        $compras = AppCategory::factory()->create(['name' => 'Compras', 'sort_order' => 2]);
        $this->app(['name' => 'Zeta', 'slug' => 'zeta', 'app_category_id' => $ventas->id]);
        $this->app(['name' => 'Alfa', 'slug' => 'alfa', 'app_category_id' => $compras->id]);
        $favorite = $this->app(['name' => 'Beta', 'slug' => 'beta', 'app_category_id' => $compras->id]);
        $user = User::factory()->microsoft($this->company)->create();
        $user->favoriteApps()->attach($favorite);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();
        preg_match_all('/<li\s+id="app-([^"]+)".*?--logo-order: (\d+)/s', $html, $cards);

        // The favorite, shown only in Favoritos, opens the page.
        $this->assertSame(['beta' => '0', 'zeta' => '1', 'alfa' => '2'], array_combine($cards[1], $cards[2]));
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
