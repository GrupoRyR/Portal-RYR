<?php

namespace Tests\Feature\Branding;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * <x-brand-logo>: the real company logo uploaded in Admin › Empresas, never
 * the name as text when a logo exists (brand manual SGX/SDC).
 */
class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_dark_logo_sits_on_the_brand_dark_color(): void
    {
        $this->storePng('brand/segurex/logo-dark.png', 598, 96);
        $company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'logo_dark_path' => 'brand/segurex/logo-dark.png',
            'logo_light_path' => 'brand/segurex/logo-light.png',
        ]);

        $view = $this->blade('<x-brand-logo :company="$company" />', ['company' => $company]);

        $view->assertSee('bg-brand-dark', false)
            ->assertSee('src="'.Storage::disk('public')->url('brand/segurex/logo-dark.png').'"', false)
            ->assertSee('alt="Segurex Latam"', false)
            ->assertSee('width="598" height="96"', false)
            ->assertDontSee('logo-light.png', false)
            ->assertDontSee('bg-white', false);
    }

    public function test_a_light_logo_only_goes_on_a_white_pill(): void
    {
        // The SDC logo has an opaque white background: never inverted.
        $this->storePng('brand/derivados/logo-light.png', 220, 54);
        $company = Company::factory()->create([
            'name' => 'Soluciones y Derivados de Colombia S.A.S.',
            'logo_dark_path' => null,
            'logo_light_path' => 'brand/derivados/logo-light.png',
        ]);

        $view = $this->blade('<x-brand-logo :company="$company" />', ['company' => $company]);

        $view->assertSee('bg-white', false)
            ->assertSee('src="'.Storage::disk('public')->url('brand/derivados/logo-light.png').'"', false)
            ->assertSee('alt="Soluciones y Derivados de Colombia S.A.S."', false)
            ->assertDontSee('bg-brand-dark', false)
            ->assertDontSee('invert', false);
    }

    public function test_without_logos_it_shows_the_initial_and_the_name(): void
    {
        $company = Company::factory()->create([
            'name' => 'Andina Química',
            'short_name' => 'Andina',
            'logo_dark_path' => null,
            'logo_light_path' => null,
            'icon_path' => null,
        ]);

        $view = $this->blade('<x-brand-logo :company="$company">Portal de aplicaciones</x-brand-logo>', ['company' => $company]);

        $view->assertDontSee('<img', false)
            ->assertSeeInOrder(['>A</span>', 'Andina Química', 'Portal de aplicaciones'], false);
    }

    public function test_a_logo_whose_file_is_missing_falls_back_to_the_name(): void
    {
        // The seeder points at brand/logo-*.png, which only exist once uploaded.
        $company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'logo_dark_path' => null,
            'logo_light_path' => 'brand/logo-segurex.png',
            'icon_path' => null,
        ]);

        $this->blade('<x-brand-logo :company="$company" />', ['company' => $company])
            ->assertDontSee('<img', false)
            ->assertSeeInOrder(['>S</span>', 'Segurex Latam'], false);

        // Uploaded later at the same fixed path: shown from then on.
        $this->storePng('brand/logo-segurex.png', 220, 54);

        $this->blade('<x-brand-logo :company="$company" />', ['company' => $company])
            ->assertSee('alt="Segurex Latam"', false)
            ->assertSee('width="220" height="54"', false)
            ->assertSee('bg-white', false);
    }

    public function test_a_missing_dark_logo_falls_back_to_the_light_one(): void
    {
        $this->storePng('brand/derivados/logo-light.png', 220, 54);
        $company = Company::factory()->create([
            'logo_dark_path' => 'brand/derivados/missing-dark.png',
            'logo_light_path' => 'brand/derivados/logo-light.png',
        ]);

        $this->blade('<x-brand-logo :company="$company" />', ['company' => $company])
            ->assertSee('logo-light.png', false)
            ->assertSee('bg-white', false)
            ->assertDontSee('bg-brand-dark', false);
    }

    public function test_it_uses_the_signed_in_users_company_by_default(): void
    {
        $this->storePng('brand/segurex/logo-dark.png', 598, 96);
        $company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/segurex/logo-dark.png']);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->blade('<x-brand-logo />')->assertSee('alt="Segurex Latam"', false);
    }

    private function storePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put($path, ob_get_clean());
    }
}
