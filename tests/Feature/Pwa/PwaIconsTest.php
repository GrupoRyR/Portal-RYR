<?php

namespace Tests\Feature\Pwa;

use App\Models\Company;
use App\Pwa\PwaIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PwaIconsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function companyWithIcon(string $bytes, string $path = 'brand/segurex/icon-k3y.png'): Company
    {
        Storage::disk('public')->put($path, $bytes);

        return Company::factory()->create(['slug' => 'segurex', 'icon_path' => $path]);
    }

    /**
     * @return array{int, int, int, int}
     */
    private function pixel(string $path, int $x, int $y): array
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$color['red'], $color['green'], $color['blue'], $color['alpha']];
    }

    public function test_it_generates_every_size_from_the_brand_icon(): void
    {
        $company = $this->companyWithIcon(PwaFixtures::png(512, 512, [0xFF, 0x67, 0x1F]));

        app(PwaIcons::class)->for($company);

        $disk = Storage::disk('public');
        foreach (['192' => 192, '512' => 512, 'maskable-512' => 512, 'apple-180' => 180] as $variant => $size) {
            $path = "brand/segurex/pwa/icon-k3y-{$variant}.png";
            $disk->assertExists($path);

            $info = getimagesizefromstring($disk->get($path));
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], $variant);
        }
    }

    public function test_the_maskable_and_apple_icons_fill_the_edge_with_the_icon_background(): void
    {
        $company = $this->companyWithIcon(PwaFixtures::png(512, 512, [0xFF, 0x67, 0x1F]));

        app(PwaIcons::class)->for($company);

        // Opaque everywhere: launchers crop maskable icons to any shape and
        // iOS paints transparent pixels black.
        $this->assertSame([0xFF, 0x67, 0x1F, 0], $this->pixel('brand/segurex/pwa/icon-k3y-maskable-512.png', 0, 0));
        $this->assertSame([0xFF, 0x67, 0x1F, 0], $this->pixel('brand/segurex/pwa/icon-k3y-apple-180.png', 0, 0));
        $this->assertSame([0xFF, 0x67, 0x1F, 0], $this->pixel('brand/segurex/pwa/icon-k3y-maskable-512.png', 256, 256));
    }

    public function test_a_logo_on_a_transparent_background_gets_a_white_safe_zone(): void
    {
        $company = $this->companyWithIcon(PwaFixtures::png(512, 512, [0x18, 0x3F, 0x94], transparentEdge: true));

        app(PwaIcons::class)->for($company);

        $this->assertSame([0xFF, 0xFF, 0xFF, 0], $this->pixel('brand/segurex/pwa/icon-k3y-maskable-512.png', 0, 0));
        $this->assertSame([0x18, 0x3F, 0x94, 0], $this->pixel('brand/segurex/pwa/icon-k3y-maskable-512.png', 256, 256));
    }

    public function test_generated_files_are_reused_and_stale_ones_removed(): void
    {
        $disk = Storage::disk('public');
        $disk->put('brand/segurex/pwa/icon-old-192.png', 'stale');
        $company = $this->companyWithIcon(PwaFixtures::png(512, 512, [0xFF, 0x67, 0x1F]));

        $first = app(PwaIcons::class)->for($company);
        $disk->put('brand/segurex/pwa/icon-k3y-192.png', 'kept');
        $second = app(PwaIcons::class)->for($company);

        $this->assertEquals($first, $second);
        $this->assertSame('kept', $disk->get('brand/segurex/pwa/icon-k3y-192.png'));
        $disk->assertMissing('brand/segurex/pwa/icon-old-192.png');
    }

    public function test_a_missing_or_broken_icon_falls_back_to_the_portal_icons(): void
    {
        $broken = $this->companyWithIcon('not a png');
        $missing = Company::factory()->create(['icon_path' => 'brand/nowhere/icon-x.png']);

        foreach ([$broken, $missing, null] as $company) {
            $icons = app(PwaIcons::class)->for($company);

            $this->assertSame(asset('icons/portal-192.png'), $icons->any192);
            $this->assertSame(asset('icons/portal-512.png'), $icons->any512);
            $this->assertSame(asset('icons/portal-maskable-512.png'), $icons->maskable512);
            $this->assertSame(asset('icons/portal-apple-180.png'), $icons->apple180);
        }

        Storage::disk('public')->assertMissing('brand/segurex/pwa/icon-k3y-192.png');
    }
}
