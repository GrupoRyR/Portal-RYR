<?php

namespace Tests\Feature\Pwa;

use App\Branding\BrandPalette;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function brandedCompany(): Company
    {
        $company = Company::factory()->create([
            'name' => 'Derivados de Colombia',
            'short_name' => 'Derivados',
            'slug' => 'derivados',
            'color_primary' => '#183F94',
            'color_accent' => '#3E60A8',
            'color_dark' => '#03256C',
        ]);

        $path = 'brand/derivados/icon-abc123.png';
        Storage::disk('public')->put($path, PwaFixtures::png(512, 512, [0x18, 0x3F, 0x94]));
        $company->forceFill(['icon_path' => $path])->save();

        return $company;
    }

    public function test_guests_get_the_neutral_portal_manifest(): void
    {
        $response = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        $manifest = $response->json();
        $this->assertSame(config('app.name'), $manifest['name']);
        $this->assertSame(config('app.name'), $manifest['short_name']);
        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('es', $manifest['lang']);
        $this->assertSame(BrandPalette::neutral()->tint, $manifest['background_color']);
        $this->assertSame('#F5F5F2', $manifest['theme_color']);

        $this->assertSame([
            ['src' => asset('icons/portal-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/portal-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/portal-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ], $manifest['icons']);
    }

    public function test_the_portal_icons_exist_with_their_declared_sizes(): void
    {
        foreach (['portal-192.png' => 192, 'portal-512.png' => 512, 'portal-maskable-512.png' => 512, 'portal-apple-180.png' => 180] as $file => $size) {
            $info = getimagesize(public_path('icons/'.$file));

            $this->assertNotFalse($info, $file);
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], $file);
        }
    }

    public function test_signed_in_users_get_their_company_name_colors_and_icons(): void
    {
        $company = $this->brandedCompany();
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('Derivados de Colombia', $manifest['name']);
        $this->assertSame('Derivados', $manifest['short_name']);
        $this->assertSame(BrandPalette::forCompany($company)->tint, $manifest['background_color']);

        $disk = Storage::disk('public');
        $this->assertSame([
            ['src' => $disk->url('brand/derivados/pwa/icon-abc123-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $disk->url('brand/derivados/pwa/icon-abc123-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $disk->url('brand/derivados/pwa/icon-abc123-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ], $manifest['icons']);
    }

    public function test_companies_without_an_icon_get_the_portal_icons(): void
    {
        $company = Company::factory()->create(['icon_path' => null]);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->assertSame(asset('icons/portal-192.png'), $this->get('/manifest.webmanifest')->json('icons.0.src'));
    }

    public function test_the_pages_link_the_manifest_with_credentials_and_the_apple_icon(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="'.url('/manifest.webmanifest').'" crossorigin="use-credentials">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.asset('icons/portal-apple-180.png').'">', false)
            ->assertSee('<meta name="mobile-web-app-capable" content="yes">', false)
            ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="'.config('app.name').'">', false);

        $company = $this->brandedCompany();
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="apple-touch-icon" href="'.Storage::disk('public')->url('brand/derivados/pwa/icon-abc123-apple-180.png').'">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Derivados">', false);
    }
}
