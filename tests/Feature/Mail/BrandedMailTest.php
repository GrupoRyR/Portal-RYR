<?php

namespace Tests\Feature\Mail;

use App\Branding\MailBrand;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Portal emails on the official brand manual SGX/SDC: Helvetica/Arial,
 * the company logo as an image on its dark color and the button on its
 * button color. Without a company they stay neutral.
 */
class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_company_mail_shows_its_logo_and_colors(): void
    {
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');
        $company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'logo_dark_path' => 'brand/segurex/logo-dark.png',
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
            'color_ink' => '#943E15',
        ]);

        $html = (string) $this->mail(MailBrand::for($company))->render();

        $this->assertStringContainsString('src="'.Storage::disk('public')->url('brand/segurex/logo-dark.png').'"', $html);
        $this->assertStringContainsString('alt="Segurex Latam"', $html);
        $this->assertStringContainsString('background-color: #58595B', $html);
        $this->assertStringContainsString('background-color: #C6531C', $html);
        $this->assertStringContainsString('Helvetica, Arial', $html);
        $this->assertStringNotContainsString('Segoe UI', $html);
    }

    public function test_the_logo_declares_its_size_at_28px_tall(): void
    {
        // Outlook ignores CSS sizes: without attributes it shows the file at
        // its natural size. A 400×100 logo is 112×28 in the band.
        $image = imagecreatetruecolor(400, 100);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('brand/segurex/logo-dark.png', (string) ob_get_clean());
        $company = Company::factory()->create(['logo_dark_path' => 'brand/segurex/logo-dark.png']);

        $html = (string) $this->mail(MailBrand::for($company))->render();

        $this->assertStringContainsString('width="112" height="28"', $html);
    }

    public function test_the_greeting_and_the_fallback_link_use_the_brand_ink(): void
    {
        $company = Company::factory()->create([
            'color_primary' => '#F26522',
            'color_dark' => '#58595B',
            'color_ink' => '#943E15',
        ]);

        $html = (string) $this->mail(MailBrand::for($company))->render();

        $this->assertMatchesRegularExpression('/<h1 style="[^"]*color: #943E15;[^"]*">Hola:<\/h1>/', $html);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/apps\.example\.com\/admin\/empresas\/segurex" style="[^"]*color: #943E15;/', $html);
    }

    public function test_a_light_only_logo_goes_on_white(): void
    {
        Storage::disk('public')->put('brand/derivados/logo-light.png', 'png');
        $company = Company::factory()->create([
            'name' => 'Soluciones y Derivados',
            'logo_dark_path' => null,
            'logo_light_path' => 'brand/derivados/logo-light.png',
            'color_primary' => '#0B5FA5',
            'color_dark' => '#073B66',
        ]);

        $brand = MailBrand::for($company);
        $html = (string) $this->mail($brand)->render();

        $this->assertFalse($brand->logoOnDark);
        $this->assertStringContainsString('logo-light.png', $html);
        $this->assertStringContainsString('background-color: #FFFFFF', $html);
    }

    public function test_without_a_brand_the_mail_is_neutral(): void
    {
        $html = (string) (new MailMessage)->greeting('Hola:')->line('Prueba')->action('Ver', 'https://example.com')->render();

        $this->assertStringContainsString(config('app.name'), $html);
        $this->assertStringContainsString('Helvetica, Arial', $html);
        $this->assertStringNotContainsString('Segoe UI', $html);
        $this->assertStringNotContainsString('<img', $html);
        // Spanish copy instead of the framework's English defaults.
        $this->assertStringContainsString('Si el botón «Ver» no funciona', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_a_missing_logo_file_shows_the_name(): void
    {
        $company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/logo-segurex.png']);

        $brand = MailBrand::for($company);

        $this->assertNull($brand->logoUrl);
        $this->assertStringContainsString('Segurex Latam', (string) $this->mail($brand)->render());
    }

    private function mail(MailBrand $brand): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', ['brand' => $brand])
            ->greeting('Hola:')
            ->line('La empresa quedó activa.')
            ->action('Ver la empresa', 'https://apps.example.com/admin/empresas/segurex');
    }
}
