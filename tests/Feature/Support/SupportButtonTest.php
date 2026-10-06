<?php

namespace Tests\Feature\Support;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Soporte TI» (brief §4): the button opens /soporte, which sends the person
 * to the ticket platform with where they come from and nothing personal.
 */
class SupportButtonTest extends TestCase
{
    use RefreshDatabase;

    private const SUPPORT_URL = 'https://soporte.segurex.com.co/tickets/nuevo';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez', 'email' => 'ana@segurex.com.co']);
    }

    private function configure(?string $url = self::SUPPORT_URL): void
    {
        app(PortalSettings::class)->update(['support_url' => $url], null);
    }

    private function openableApp(array $attributes = [], ?Company $company = null): App
    {
        $company ??= $this->company;
        $app = App::factory()->forCompanies($company)->create($attributes);
        AppAccessRule::forCompany($app, $company);

        return $app;
    }

    private function launch(App $app, int $minutesAgo, ?User $user = null): void
    {
        AppLaunch::create([
            'user_id' => ($user ?? $this->user)->id,
            'app_id' => $app->id,
            'company_id' => $this->company->id,
            'device' => 'desktop',
            'launched_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function supportQuery(string $uri = '/soporte'): array
    {
        $location = $this->actingAs($this->user)->get($uri)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::SUPPORT_URL.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    // --- /soporte ---------------------------------------------------------------

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->configure();

        $this->get('/soporte')->assertRedirect(route('login'));
    }

    public function test_it_is_not_found_without_a_support_url(): void
    {
        $this->actingAs($this->user)->get('/soporte')->assertNotFound();
    }

    public function test_it_sends_the_origin_and_the_company_only(): void
    {
        $this->configure();

        $this->assertSame(['origen' => 'portal', 'empresa' => 'segurex'], $this->supportQuery());
    }

    public function test_it_adds_the_last_app_opened_in_the_last_30_minutes(): void
    {
        $this->configure();
        $older = $this->openableApp(['slug' => 'cartera']);
        $newer = $this->openableApp(['slug' => 'toma-pedidos']);
        $this->launch($older, 20);
        $this->launch($newer, 5);

        $this->assertSame(['origen' => 'portal', 'empresa' => 'segurex', 'app' => 'toma-pedidos'], $this->supportQuery());
    }

    public function test_an_app_opened_more_than_30_minutes_ago_is_left_out(): void
    {
        $this->configure();
        $this->launch($this->openableApp(['slug' => 'cartera']), 31);

        $this->assertArrayNotHasKey('app', $this->supportQuery());
    }

    public function test_an_app_the_user_can_no_longer_see_is_left_out(): void
    {
        $this->configure();
        $app = $this->openableApp(['slug' => 'cartera']);
        $this->launch($app, 5);
        $app->update(['status' => App::STATUS_HIDDEN]);

        $this->assertArrayNotHasKey('app', $this->supportQuery());
    }

    public function test_other_peoples_launches_do_not_count(): void
    {
        $this->configure();
        $this->launch($this->openableApp(['slug' => 'cartera']), 5, User::factory()->microsoft($this->company)->create());

        $this->assertArrayNotHasKey('app', $this->supportQuery());
    }

    public function test_the_app_of_the_page_is_used_when_the_user_can_see_it(): void
    {
        $this->configure();
        $this->launch($this->openableApp(['slug' => 'cartera']), 5);
        $this->openableApp(['slug' => 'nomina', 'status' => App::STATUS_MAINTENANCE]);

        $this->assertSame('nomina', $this->supportQuery('/soporte?app=nomina')['app']);
    }

    public function test_an_app_the_user_cannot_see_is_ignored(): void
    {
        $this->configure();
        $this->launch($this->openableApp(['slug' => 'cartera']), 5);
        $this->openableApp(['slug' => 'secreta'], Company::factory()->create());

        $this->assertSame('cartera', $this->supportQuery('/soporte?app=secreta')['app']);
        $this->assertSame('cartera', $this->supportQuery('/soporte?app=no-existe')['app']);
        $this->assertSame('cartera', $this->supportQuery('/soporte?app[]=x')['app']);
    }

    public function test_the_configured_query_string_is_kept(): void
    {
        $this->configure(self::SUPPORT_URL.'?form=ti&origen=web');

        $location = $this->actingAs($this->user)->get('/soporte')->headers->get('Location');

        $this->assertSame(self::SUPPORT_URL.'?form=ti&origen=portal&empresa=segurex', $location);
    }

    public function test_no_personal_data_leaves_the_portal(): void
    {
        $this->configure();
        $this->launch($this->openableApp(['slug' => 'cartera']), 5);

        $location = $this->actingAs($this->user)->get('/soporte')->headers->get('Location');

        foreach (['ana', 'Ana', 'segurex.com.co/', (string) $this->user->id, 'P%C3%A9rez'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) parse_url($location, PHP_URL_QUERY));
        }
    }

    // --- Buttons ----------------------------------------------------------------

    public function test_no_button_anywhere_without_a_support_url(): void
    {
        $local = $this->openableApp(['slug' => 'camaras', 'type' => App::TYPE_LOCAL, 'url' => null, 'open_in' => App::OPEN_SAME]);
        $maintenance = $this->openableApp(['slug' => 'nomina', 'status' => App::STATUS_MAINTENANCE]);

        foreach ([route('home'), route('apps.open', $local), route('apps.open', $maintenance)] as $page) {
            $this->actingAs($this->user)->get($page)->assertOk()
                ->assertDontSeeText('Soporte TI')
                ->assertDontSee(route('support'), false);
        }
    }

    public function test_the_home_shows_the_button_on_every_size_without_livewire(): void
    {
        $this->configure();

        $this->actingAs($this->user)->get(route('home'))->assertOk()
            ->assertSee('href="'.route('support').'" target="_blank" rel="noopener"', false)
            ->assertSeeText('Soporte TI')
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_the_local_app_page_offers_support_for_that_app(): void
    {
        $this->configure();
        $local = $this->openableApp(['slug' => 'camaras', 'type' => App::TYPE_LOCAL, 'url' => null, 'open_in' => App::OPEN_SAME]);

        $this->actingAs($this->user)->get(route('apps.open', $local))->assertOk()
            ->assertSee('href="'.route('support', ['app' => 'camaras']).'" target="_blank" rel="noopener"', false)
            ->assertSeeText('Soporte TI');
    }

    public function test_the_maintenance_page_offers_to_tell_support(): void
    {
        $this->configure();
        $maintenance = $this->openableApp(['slug' => 'nomina', 'status' => App::STATUS_MAINTENANCE]);

        $this->actingAs($this->user)->get(route('apps.open', $maintenance))->assertOk()
            ->assertSee('href="'.route('support', ['app' => 'nomina']).'" target="_blank" rel="noopener"', false)
            ->assertSeeText('Avisar a Soporte TI');
    }
}
