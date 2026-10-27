<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sign-in stage (docs/diseno/LoginOpcionB.html): decorative app comets
 * behind a glass card, neutral chrome, brand color only in each logo chip.
 */
class LoginStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_comets_and_the_still_tiles_are_hidden_from_assistive_technology(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div aria-hidden="true"[^>]*>\s*<ul class="signin-comets/', $html);
        $this->assertSame(11, substr_count($html, '<li class="signin-comet'));
        $this->assertStringContainsString('class="signin-floats', $html);
    }

    public function test_the_sign_in_sits_on_the_glass_card_with_one_heading(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<section aria-labelledby="titulo" class="signin-card /', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<body class="min-h-dvh signin-stage">', $html);
    }

    public function test_a_dark_logo_sits_on_its_own_company_dark_color(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark-abc.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 103"/>');
        Company::factory()->create([
            'name' => 'Segurex Latam',
            'logo_light_path' => null,
            'logo_dark_path' => 'brand/segurex/logo-dark-abc.svg',
            'color_primary' => '#F26522',
            'color_dark' => '#58595b',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('style="--brand-dark: #58595B"', false)
            ->assertSee('alt="Segurex Latam"', false);
    }

    public function test_an_invalid_dark_color_never_reaches_the_style_attribute(): void
    {
        Company::factory()->create(['name' => 'Andina', 'color_primary' => '#0F8A7E', 'color_dark' => 'red;}body{display:none']);

        $html = $this->get(route('login'))->assertOk()->getContent();

        // The dark ink derived from the primary takes its place.
        $this->assertMatchesRegularExpression('/style="--brand-dark: #[0-9A-F]{6}"/', $html);
        $this->assertStringNotContainsString('display:none', $html);
    }
}
