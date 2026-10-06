<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AppEditor;
use App\Models\App;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AppIconTest extends TestCase
{
    use RefreshDatabase;

    private const SQUARE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" rx="14" fill="#B45309"/></svg>';

    private const MALICIOUS_SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 48 48" onload="alert(1)">
          <script>alert(2)</script>
          <a xlink:href="javascript:alert(3)"><rect width="48" height="48" fill="#B45309" onclick="alert(4)"/></a>
          <image href="https://evil.example/track.png" width="1" height="1"/>
        </svg>
        SVG;

    private App $cartera;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->cartera = App::factory()->create(['slug' => 'cartera']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('portal_admin');
    }

    public function test_a_square_svg_is_sanitized_and_stored_under_the_app_folder(): void
    {
        $this->editor()
            ->set('iconLight', $this->svg(self::MALICIOUS_SVG))
            ->call('save')
            ->assertHasNoErrors();

        $path = $this->cartera->fresh()->icon_path;

        $this->assertStringStartsWith('icons/apps/cartera/', $path);
        $this->assertStringEndsWith('.svg', $path);

        $stored = Storage::disk('public')->get($path);
        $this->assertStringContainsString('<rect', $stored);
        foreach (['<script', 'onload', 'onclick', 'javascript:', 'evil.example'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }

        $this->assertSame(Storage::disk('public')->url($path), $this->cartera->fresh()->iconUrl());
    }

    public function test_a_non_square_svg_is_rejected(): void
    {
        $this->editor()
            ->set('iconLight', $this->svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24"><rect width="48" height="24"/></svg>'))
            ->call('save')
            ->assertHasErrors('iconLight');

        $this->editor()
            ->set('iconLight', $this->svg('<svg xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48"/></svg>'))
            ->call('save')
            ->assertHasErrors('iconLight');

        $this->editor()->set('iconLight', $this->svg('not an svg'))->call('save')->assertHasErrors('iconLight');
    }

    public function test_png_and_webp_icons_must_be_square_and_at_least_192_px(): void
    {
        $this->editor()->set('iconLight', UploadedFile::fake()->image('icon.png', 192, 192))->call('save')->assertHasNoErrors();
        $this->editor()->set('iconDark', UploadedFile::fake()->image('icon.webp', 512, 512))->call('save')->assertHasNoErrors();

        $this->editor()->set('iconLight', UploadedFile::fake()->image('icon.png', 128, 128))->call('save')
            ->assertHasErrors('iconLight')
            ->assertSee('cuadrado de al menos 192 px');
        $this->editor()->set('iconLight', UploadedFile::fake()->image('icon.png', 400, 200))->call('save')->assertHasErrors('iconLight');
        $this->editor()->set('iconLight', UploadedFile::fake()->image('icon.jpg', 256, 256))->call('save')->assertHasErrors('iconLight');
        // A PNG name on JPEG bytes.
        $jpeg = UploadedFile::fake()->image('x.jpg', 256, 256);
        $this->editor()->set('iconLight', UploadedFile::fake()->createWithContent('icon.png', (string) file_get_contents($jpeg->getRealPath())))
            ->call('save')->assertHasErrors('iconLight');
    }

    public function test_icons_over_512_kb_are_rejected(): void
    {
        $this->editor()
            ->set('iconLight', UploadedFile::fake()->image('icon.png', 256, 256)->size(600))
            ->call('save')
            ->assertHasErrors('iconLight');
    }

    public function test_replacing_an_icon_deletes_the_old_file(): void
    {
        $this->editor()->set('iconLight', $this->svg(self::SQUARE_SVG))->call('save')->assertHasNoErrors();
        $old = $this->cartera->fresh()->icon_path;

        $this->editor()->set('iconLight', UploadedFile::fake()->image('icon.png', 256, 256))->call('save')->assertHasNoErrors();
        $new = $this->cartera->fresh()->icon_path;

        $this->assertNotSame($old, $new);
        $this->assertSame([$new], Storage::disk('public')->allFiles('icons/apps/cartera'));
    }

    public function test_bundled_icons_outside_the_upload_folder_are_never_deleted(): void
    {
        $this->cartera->update(['icon_path' => 'icons/apps/cartera.svg', 'icon_dark_path' => 'icons/apps/cartera-dark.svg']);
        Storage::disk('public')->put('icons/apps/cartera.svg', 'bundled');

        $this->editor()->set('iconLight', $this->svg(self::SQUARE_SVG))->call('save')->assertHasNoErrors();

        Storage::disk('public')->assertExists('icons/apps/cartera.svg');
        $this->assertSame(asset('icons/apps/cartera-dark.svg'), $this->cartera->fresh()->iconUrl(dark: true));
    }

    public function test_a_failed_save_leaves_no_orphaned_upload(): void
    {
        App::saving(fn () => throw new \RuntimeException('Database unavailable.'));

        try {
            $this->editor()->set('iconLight', $this->svg(self::SQUARE_SVG))->set('iconDark', $this->svg(self::SQUARE_SVG))->call('save');
            $this->fail('The save should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database unavailable.', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles('icons/apps/cartera'));
        $this->assertNull($this->cartera->fresh()->icon_path);
    }

    public function test_the_editor_previews_the_icon_in_light_dark_and_solid(): void
    {
        $this->editor()
            ->assertSee('Vista en fondo claro')
            ->assertSee('Vista en fondo oscuro')
            ->assertSee('Vista sobre el color de acento');
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->admin)->test(AppEditor::class, ['app' => $this->cartera->fresh()]);
    }

    private function svg(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('icon.svg', $content);
    }
}
