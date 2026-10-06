<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sign-in logos declare their intrinsic size so the browser reserves
 * their space before they load (no layout shift).
 */
class LoginLogoSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_png_logo_declares_its_pixel_size(): void
    {
        $image = imagecreatetruecolor(240, 60);
        ob_start();
        imagepng($image);
        Storage::disk('public')->put('brand/segurex/logo-light-abc.png', ob_get_clean());

        Company::factory()->create(['logo_light_path' => 'brand/segurex/logo-light-abc.png']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('width="240" height="60"', false);
    }

    public function test_an_svg_logo_declares_its_view_box_size(): void
    {
        Storage::disk('public')->put(
            'brand/derivados/logo-light-abc.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 730 180"><rect width="730" height="180"/></svg>',
        );

        Company::factory()->create(['logo_light_path' => 'brand/derivados/logo-light-abc.svg']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('width="730" height="180"', false);
    }

    public function test_an_unreadable_logo_still_renders_without_a_size(): void
    {
        Company::factory()->create(['logo_light_path' => 'brand/missing/logo-light-abc.png']);

        $response = $this->get(route('login'))->assertOk();

        $response->assertSee('brand/missing/logo-light-abc.png', false);
        $response->assertDontSee('width="0"', false);
    }
}
