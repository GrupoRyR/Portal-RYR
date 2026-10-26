<?php

namespace Tests\Feature\Pwa;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser and installed-app bars take --brand-dark, the color of the
 * header band behind the company logo (brand manual SGX/SDC). Signed out,
 * the neutral grounds of each color scheme stay.
 */
class BrandThemeColorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

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
    }

    public function test_the_manifest_theme_is_the_company_dark_color(): void
    {
        $this->actingAs(User::factory()->microsoft($this->company)->create());

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('#58595B', $manifest['theme_color']);
    }

    public function test_pages_of_a_company_paint_the_bar_with_its_dark_color(): void
    {
        $this->actingAs(User::factory()->microsoft($this->company)->create());

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="theme-color" content="#58595B">', $html);
        $this->assertSame(1, substr_count($html, 'name="theme-color"'));
    }

    public function test_signed_out_pages_keep_the_neutral_bars(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<meta name="theme-color" content="#F5F5F2" media="(prefers-color-scheme: light)">', false)
            ->assertSee('<meta name="theme-color" content="#121315" media="(prefers-color-scheme: dark)">', false);

        $this->assertSame('#F5F5F2', $this->get('/manifest.webmanifest')->json('theme_color'));
    }
}
