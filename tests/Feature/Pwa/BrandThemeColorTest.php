<?php

namespace Tests\Feature\Pwa;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser and installed-app bars take --brand-dark only on pages that
 * open with the header band behind the company logo (brand manual
 * SGX/SDC), so the bar runs into the band. Every other page, and every
 * signed-out page, keeps the neutral grounds of each color scheme.
 */
class BrandThemeColorTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND_META = '<meta name="theme-color" content="#58595B">';

    private const NEUTRAL_LIGHT_META = '<meta name="theme-color" content="#F5F5F2" media="(prefers-color-scheme: light)">';

    private const NEUTRAL_DARK_META = '<meta name="theme-color" content="#121315" media="(prefers-color-scheme: dark)">';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create([
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
            'color_ink' => '#943E15',
            'color_text' => '#58595B',
        ]);
        $this->user = User::factory()->microsoft($this->company)->create();
    }

    public function test_the_manifest_theme_is_the_company_dark_color(): void
    {
        $manifest = $this->actingAs($this->user)->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('#58595B', $manifest['theme_color']);
    }

    public function test_the_home_band_paints_the_bar_with_the_dark_color(): void
    {
        $this->assertBrandBar($this->actingAs($this->user)->get('/')->assertOk()->getContent());
    }

    public function test_the_admin_band_paints_the_bar_with_the_dark_color(): void
    {
        $this->seed(RoleSeeder::class);
        $this->user->assignRole('super_admin');

        $this->assertBrandBar($this->actingAs($this->user)->get(route('admin.categories.index'))->assertOk()->getContent());
    }

    public function test_a_page_without_the_band_keeps_the_neutral_bars(): void
    {
        $this->assertNeutralBars($this->actingAs($this->user)->get(route('admin.index'))->assertForbidden()->getContent());
    }

    public function test_the_microsoft_required_page_keeps_the_neutral_bars(): void
    {
        $this->actingAs($this->user);

        $this->assertNeutralBars(view('errors.microsoft-required')->render());
    }

    public function test_signed_out_pages_keep_the_neutral_bars(): void
    {
        $this->assertNeutralBars($this->get(route('login'))->assertOk()->getContent());

        $this->assertSame('#F5F5F2', $this->get('/manifest.webmanifest')->json('theme_color'));
    }

    private function assertBrandBar(string $html): void
    {
        $this->assertStringContainsString(self::BRAND_META, $html);
        $this->assertSame(1, substr_count($html, 'name="theme-color"'));
    }

    private function assertNeutralBars(string $html): void
    {
        $this->assertStringContainsString(self::NEUTRAL_LIGHT_META, $html);
        $this->assertStringContainsString(self::NEUTRAL_DARK_META, $html);
        $this->assertStringNotContainsString('#58595B">', $html);
    }
}
