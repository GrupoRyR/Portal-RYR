<?php

namespace Tests\Feature\Branding;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Secondary pages of a signed-in user (announcements, local app,
 * maintenance) open with the company logo on a --brand-dark band and use
 * the brand colors; pages that may show without a company (errors,
 * emergency access) stay neutral.
 */
class SecondaryPagesBrandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');

        $this->company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/segurex/logo-dark.png']);
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    public function test_the_announcements_page_opens_with_the_brand_band(): void
    {
        $this->assertBand($this->actingAs($this->user)->get(route('announcements.index'))->assertOk());
    }

    public function test_the_local_app_page_opens_with_the_brand_band_and_brand_steps(): void
    {
        $app = $this->app(['type' => App::TYPE_LOCAL, 'url' => null, 'local_instructions' => "Abre el menú Inicio\nBusca la app"]);

        $response = $this->actingAs($this->user)->get(route('apps.open', $app))->assertOk();

        $this->assertBand($response);
        $response->assertSee('bg-brand-button text-[0.8125rem] font-bold text-white', false)
            ->assertDontSee('text-on-fg', false);
    }

    public function test_the_maintenance_page_opens_with_the_brand_band(): void
    {
        $app = $this->app(['status' => App::STATUS_MAINTENANCE]);

        $this->assertBand($this->actingAs($this->user)->get(route('apps.open', $app))->assertOk());
    }

    public function test_the_forbidden_page_uses_the_brand_button_without_a_band(): void
    {
        $this->actingAs($this->user)->get(route('admin.index'))
            ->assertForbidden()
            ->assertSee('bg-brand-button', false)
            ->assertDontSee('brand-band', false)
            ->assertDontSee('text-on-fg', false);
    }

    public function test_the_emergency_sign_in_stays_neutral(): void
    {
        $this->get(route('emergency.login'))
            ->assertOk()
            ->assertDontSee('brand-band', false)
            ->assertDontSee('uppercase', false);
    }

    private function assertBand(TestResponse $response): void
    {
        $response->assertSee('<header class="brand-band', false)
            ->assertSee('alt="Segurex Latam"', false);
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
}
