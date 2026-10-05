<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    private const MALICIOUS_SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">
          <script>alert(2)</script>
          <a xlink:href="javascript:alert(3)"><rect width="10" height="10" fill="#FF671F" onclick="alert(4)"/></a>
          <image href="https://evil.example/track.png" width="1" height="1"/>
        </svg>
        SVG;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'slug' => 'segurex',
            'domains' => ['segurex.com'],
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->admin)->test(CompanyEditor::class, ['company' => $this->company]);
    }

    public function test_a_png_logo_is_stored_under_the_company_folder(): void
    {
        $this->editor()
            ->set('logoLight', UploadedFile::fake()->image('logo.png', 400, 120))
            ->call('save')
            ->assertHasNoErrors();

        $path = $this->company->fresh()->logo_light_path;

        $this->assertStringStartsWith('brand/segurex/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        Storage::disk('public')->put('brand/segurex/old-dark.png', 'old');
        $this->company->update(['logo_dark_path' => 'brand/segurex/old-dark.png']);

        $this->editor()
            ->set('logoDark', UploadedFile::fake()->image('dark.webp', 400, 120))
            ->call('save')
            ->assertHasNoErrors();

        $path = $this->company->fresh()->logo_dark_path;
        $this->assertNotSame('brand/segurex/old-dark.png', $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing('brand/segurex/old-dark.png');
    }

    public function test_svg_logos_are_sanitized(): void
    {
        $this->editor()
            ->set('logoLight', UploadedFile::fake()->createWithContent('logo.svg', self::MALICIOUS_SVG))
            ->call('save')
            ->assertHasNoErrors();

        $path = $this->company->fresh()->logo_light_path;
        $this->assertStringEndsWith('.svg', $path);

        $svg = Storage::disk('public')->get($path);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsStringIgnoringCase('<script', $svg);
        $this->assertStringNotContainsStringIgnoringCase('onload', $svg);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $svg);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $svg);
        $this->assertStringNotContainsString('evil.example', $svg);
    }

    public function test_files_that_are_not_svg_are_rejected_even_with_an_svg_name(): void
    {
        $this->editor()
            ->set('logoLight', UploadedFile::fake()->createWithContent('logo.svg', '<html><body><script>alert(1)</script></body></html>'))
            ->call('save')
            ->assertHasErrors(['logoLight']);

        $this->assertNull($this->company->fresh()->logo_light_path);
    }

    public function test_oversized_and_wrong_type_files_are_rejected(): void
    {
        $this->editor()
            ->set('logoLight', UploadedFile::fake()->image('big.png', 400, 120)->size(1025))
            ->call('save')
            ->assertHasErrors(['logoLight']);

        $this->editor()
            ->set('logoLight', UploadedFile::fake()->image('photo.jpg', 400, 120))
            ->call('save')
            ->assertHasErrors(['logoLight']);

        $this->editor()
            ->set('logoLight', UploadedFile::fake()->createWithContent('fake.png', 'not really a png'))
            ->call('save')
            ->assertHasErrors(['logoLight']);

        $this->assertNull($this->company->fresh()->logo_light_path);
    }

    public function test_the_icon_must_be_a_512_pixel_png(): void
    {
        $this->editor()
            ->set('icon', UploadedFile::fake()->image('icon.png', 256, 256))
            ->call('save')
            ->assertHasErrors(['icon']);

        $this->editor()
            ->set('icon', UploadedFile::fake()->image('icon.webp', 512, 512))
            ->call('save')
            ->assertHasErrors(['icon']);

        $this->editor()
            ->set('icon', UploadedFile::fake()->image('icon.png', 512, 512))
            ->call('save')
            ->assertHasNoErrors();

        Storage::disk('public')->assertExists($this->company->fresh()->icon_path);
    }

    public function test_the_sign_in_page_shows_uploaded_logos(): void
    {
        Storage::disk('public')->put('brand/segurex/logo.png', 'png');
        $this->company->update(['short_name' => 'Segurex', 'logo_light_path' => 'brand/segurex/logo.png']);
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'short_name' => 'Derivados']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('src="'.Storage::disk('public')->url('brand/segurex/logo.png').'"', false)
            ->assertSee('alt="Segurex Latam"', false)
            ->assertSee('Derivados');
    }
}
