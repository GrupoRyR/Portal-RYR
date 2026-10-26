<?php

namespace Tests\Feature\Home;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Home screen on the official brand manual SGX/SDC: the logo on a band of
 * --brand-dark, labels in sentence case and brand colors by role.
 */
class HomeBrandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/segurex/logo-dark.png']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Ana María Pérez']);
    }

    public function test_the_header_is_a_brand_dark_band_with_the_logo_on_it(): void
    {
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');

        $this->home()
            ->assertSee('<header class="brand-band', false)
            ->assertSee('alt="Segurex Latam"', false)
            // The white logo sits straight on the band: no chip around it.
            ->assertDontSee('bg-brand-dark px-3', false);
    }

    public function test_section_labels_are_in_sentence_case(): void
    {
        $category = AppCategory::factory()->create(['name' => 'Ventas']);
        $app = App::factory()->forCompanies($this->company)->create(['app_category_id' => $category->id]);
        AppAccessRule::forCompany($app, $this->company);

        $this->home()
            ->assertSeeText('Favoritos')
            ->assertSeeText('Microsoft 365')
            ->assertDontSee('uppercase', false);
    }

    public function test_the_favorite_star_uses_the_brand_accent(): void
    {
        $app = App::factory()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($app, $this->company);

        $this->home()
            ->assertSee('aria-pressed:text-brand-accent', false)
            ->assertDontSee('text-brand-primary', false);
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->user->fresh())->get('/')->assertOk();
    }
}
