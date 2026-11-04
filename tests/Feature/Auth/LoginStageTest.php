<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sign-in «Vitrina» (approved sketch, 2026-11-04): company photos fade
 * behind a neutral veil, under one glass card. Neutral chrome: brand color
 * only in the One mark and each company's logo chip.
 */
class LoginStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_photo_slides_and_the_veil_are_hidden_from_assistive_technology(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('<body class="min-h-dvh vitrina">', $html);
        $this->assertMatchesRegularExpression('/<div class="vitrina-slides" aria-hidden="true">\s*(<div class="vitrina-slide"><\/div>\s*){3}<\/div>/', $html);
        $this->assertStringContainsString('<div class="vitrina-veil" aria-hidden="true"></div>', $html);
    }

    public function test_the_sign_in_sits_on_one_glass_card_with_one_heading(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<section aria-labelledby="titulo" class="g-card /', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('/<h1 id="titulo" class="[^"]*\btext-balance\b[^"]*">Bienvenido a One<\/h1>/', $html);
        $this->assertStringNotContainsString('hyphens-auto', $html);
    }

    public function test_the_one_mark_follows_the_theme_with_its_size_declared(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<img src="[^"]*\/images\/one\/one-color\.webp" width="220" height="103" alt="One, portal de Segurex y Soluciones y Derivados" class="[^"]*\bdark:hidden\b/', $html);
        $this->assertMatchesRegularExpression('/<img src="[^"]*\/images\/one\/one-cristal\.webp" width="220" height="103" alt="One, portal de Segurex y Soluciones y Derivados" class="hidden [^"]*\bdark:block\b/', $html);
        $this->assertFileExists(public_path('images/one/one-color.webp'));
        $this->assertFileExists(public_path('images/one/one-cristal.webp'));
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

    public function test_the_support_note_links_to_the_configured_support_url(): void
    {
        app(PortalSettings::class)->update(['support_url' => 'https://soporte.segurex.com.co/tickets/nuevo'], null);

        // Directly to the URL: the /soporte route needs a session.
        $html = $this->get(route('login'))
            ->assertOk()
            ->assertSee('<a href="https://soporte.segurex.com.co/tickets/nuevo" target="_blank" rel="noopener"', false)
            ->assertSeeText('Escribe a soporte de TI')
            ->getContent();

        // The footer links to it too.
        $this->assertMatchesRegularExpression('/<footer .*<a href="https:\/\/soporte\.segurex\.com\.co\/tickets\/nuevo"[^>]*>Soporte TI<\/a>.*<\/footer>/s', $html);
    }

    public function test_without_a_support_url_the_note_is_plain_text(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('Escribe a soporte de TI.', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('Soporte TI', $html);
    }

    public function test_the_footer_carries_the_legal_lines_and_only_known_links(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        preg_match('/<footer .*?<\/footer>/s', $html, $footer);
        $this->assertNotEmpty($footer);
        $this->assertStringContainsString('© '.now()->year.' Segurex LATAM S.A.S. · Soluciones y Derivados de Colombia S.A.S.', $footer[0]);
        $this->assertStringContainsString('Acceso exclusivo para colaboradores autorizados.', $footer[0]);
        $this->assertStringContainsString('<a href="https://www.segurex.com"', $footer[0]);
        // No URL is configured for the policy, the terms or the SDC site yet.
        $this->assertStringNotContainsString('Política de tratamiento', $footer[0]);
        $this->assertStringNotContainsString('Términos de uso', $footer[0]);
    }

    public function test_the_page_never_names_the_group_and_has_no_dead_links(): void
    {
        app(PortalSettings::class)->update(['support_url' => 'https://soporte.segurex.com.co'], null);
        Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        Company::factory()->create(['name' => 'Soluciones y Derivados de Colombia S.A.S.', 'short_name' => 'Derivados']);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/grupo/i', $html);
        $this->assertStringNotContainsString('href="#"', $html);
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
