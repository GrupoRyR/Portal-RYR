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
 * Home screen on the official brand manual SGX/SDC: the logo on its plate in
 * the glass header (<x-portal-header>), labels in sentence case and brand
 * colors by role.
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

    public function test_the_header_is_the_glass_bar_with_the_logo_on_its_plate(): void
    {
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');

        $this->home()
            ->assertSee('<header class="g-topbar', false)
            ->assertSee('alt="Segurex Latam"', false)
            // The white logo needs its --brand-dark plate on the light glass.
            ->assertSee('bg-brand-dark px-3', false)
            ->assertDontSee('brand-band', false);
    }

    public function test_section_labels_are_in_sentence_case(): void
    {
        $category = AppCategory::factory()->create(['name' => 'Ventas']);
        $app = App::factory()->forCompanies($this->company)->create(['app_category_id' => $category->id]);
        AppAccessRule::forCompany($app, $this->company);

        $html = $this->home()
            ->assertSeeText('Favoritos')
            ->assertSeeText('Accesos rápidos')
            ->getContent();

        preg_match_all('/<h[23] id="(mis-aplicaciones-titulo|favoritos-titulo|accesos-titulo|categoria-[^"]+)"[^>]*>/', $html, $headings);
        $this->assertCount(4, $headings[0]);
        foreach ($headings[0] as $heading) {
            $this->assertStringNotContainsString('uppercase', $heading);
        }
    }

    public function test_the_pressed_favorite_star_uses_the_brand_ink(): void
    {
        // The accent (#F26522) is only 3.15:1 on white: too light for a
        // state shown by color alone. The ink holds AA in both schemes.
        $app = App::factory()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($app, $this->company);

        $this->home()
            ->assertSee('aria-pressed:text-brand-ink', false)
            ->assertDontSee('aria-pressed:text-brand-accent', false)
            ->assertDontSee('text-brand-primary', false);
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->user->fresh())->get('/')->assertOk();
    }
}
