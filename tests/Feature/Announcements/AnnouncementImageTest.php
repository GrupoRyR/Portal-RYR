<?php

namespace Tests\Feature\Announcements;

use App\Announcements\AnnouncementImageStore;
use App\Announcements\AnnouncementPublisher;
use App\Models\Announcement;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AnnouncementImageTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementImageStore $images;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->images = app(AnnouncementImageStore::class);
    }

    public function test_a_wide_png_becomes_a_1280_by_720_webp_in_the_announcement_folder(): void
    {
        $announcement = Announcement::factory()->create();

        $path = $this->images->store($announcement, UploadedFile::fake()->image('banner.png', 1920, 1080));

        $this->assertMatchesRegularExpression('#^announcements/'.$announcement->id.'/[a-z0-9]{16}\.webp$#', $path);
        $this->assertImage($path, 1280, 720);
    }

    public function test_a_tall_jpeg_is_center_cropped_to_16_by_9_without_upscaling(): void
    {
        $announcement = Announcement::factory()->create();

        $path = $this->images->store($announcement, UploadedFile::fake()->image('foto.jpg', 800, 1200));

        $this->assertImage($path, 800, 450);
    }

    public function test_the_crop_keeps_the_center_of_the_picture(): void
    {
        // 300 × 100: a red band, a blue center and a red band. The 16:9 crop
        // is 178 × 100 around the center, so its middle pixel is blue.
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $path = $this->images->store(Announcement::factory()->create(), UploadedFile::fake()->createWithContent('band.png', $png));

        $stored = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame(178, imagesx($stored));
        $center = imagecolorsforindex($stored, imagecolorat($stored, 89, 50));
        $this->assertGreaterThan(200, $center['blue']);
        $this->assertLessThan(60, $center['red']);
    }

    public function test_a_webp_is_accepted(): void
    {
        $file = UploadedFile::fake()->image('foto.webp', 640, 360);

        $this->assertNull($this->images->problem($file));
        $this->assertImage($this->images->store(Announcement::factory()->create(), $file), 640, 360);
    }

    public function test_svg_files_are_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9"><script>alert(1)</script></svg>');

        $this->assertNotNull($this->images->problem($svg));

        $this->expectException(\InvalidArgumentException::class);
        $this->images->store(Announcement::factory()->create(), $svg);
    }

    public function test_a_file_that_only_claims_to_be_an_image_is_rejected(): void
    {
        $fake = UploadedFile::fake()->createWithContent('foto.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->assertSame('El archivo no es una imagen PNG, JPG o WebP válida.', $this->images->problem($fake));
    }

    public function test_files_over_2_mb_are_rejected(): void
    {
        $big = UploadedFile::fake()->image('grande.png', 100, 100)->size(2049);

        $this->assertSame('La imagen pesa más de 2 MB.', $this->images->problem($big));
    }

    public function test_the_publisher_creates_the_announcement_with_its_image_and_companies(): void
    {
        $segurex = Company::factory()->create();

        $announcement = $this->publisher()->save(new Announcement, $this->attributes([
            'audience' => Announcement::AUDIENCE_COMPANIES,
        ]), [$segurex->id], UploadedFile::fake()->image('a.png', 1600, 900));

        $this->assertTrue($announcement->exists);
        $this->assertStringStartsWith('announcements/'.$announcement->id.'/', $announcement->fresh()->image_path);
        Storage::disk('public')->assertExists($announcement->image_path);
        $this->assertSame([$segurex->id], $announcement->companies()->pluck('companies.id')->all());
    }

    public function test_replacing_the_image_deletes_the_old_file(): void
    {
        $announcement = $this->publisher()->save(new Announcement, $this->attributes(), [], UploadedFile::fake()->image('a.png', 1600, 900));
        $old = $announcement->image_path;

        $announcement = $this->publisher()->save($announcement->fresh(), $this->attributes(), [], UploadedFile::fake()->image('b.png', 1600, 900));

        $this->assertNotSame($old, $announcement->image_path);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($announcement->image_path);
    }

    public function test_removing_the_image_deletes_the_file(): void
    {
        $announcement = $this->publisher()->save(new Announcement, $this->attributes(), [], UploadedFile::fake()->image('a.png', 1600, 900));
        $old = $announcement->image_path;

        $announcement = $this->publisher()->save($announcement->fresh(), $this->attributes(), [], removeImage: true);

        $this->assertNull($announcement->fresh()->image_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_switching_to_all_companies_clears_the_chosen_companies(): void
    {
        $segurex = Company::factory()->create();
        $announcement = $this->publisher()->save(new Announcement, $this->attributes(['audience' => Announcement::AUDIENCE_COMPANIES]), [$segurex->id]);

        $this->publisher()->save($announcement->fresh(), $this->attributes(), [$segurex->id]);

        $this->assertSame(0, $announcement->companies()->count());
    }

    public function test_a_failed_save_leaves_no_orphaned_file(): void
    {
        $announcement = $this->publisher()->save(new Announcement, $this->attributes(), [], UploadedFile::fake()->image('a.png', 1600, 900));
        $old = $announcement->image_path;

        Announcement::saving(fn () => throw new RuntimeException('Database unavailable.'));

        try {
            $this->publisher()->save($announcement->fresh(), $this->attributes(['title' => 'Otro']), [], UploadedFile::fake()->image('b.png', 1600, 900));
            $this->fail('The save should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Database unavailable.', $e->getMessage());
        }

        $this->assertSame([$old], Storage::disk('public')->allFiles('announcements/'.$announcement->id));
        $this->assertSame($old, $announcement->fresh()->image_path);
    }

    public function test_a_failed_creation_leaves_no_orphaned_file(): void
    {
        // The image is written once the row exists; the unknown company then
        // breaks the foreign key and rolls everything back.
        try {
            $this->publisher()->save(new Announcement, $this->attributes(['audience' => Announcement::AUDIENCE_COMPANIES]), [999_999], UploadedFile::fake()->image('a.png', 1600, 900));
            $this->fail('The save should have failed.');
        } catch (QueryException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles('announcements'));
        $this->assertSame(0, Announcement::query()->count());
    }

    private function publisher(): AnnouncementPublisher
    {
        return app(AnnouncementPublisher::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return [
            'title' => 'Ya puedes entrar con Microsoft',
            'body' => 'Toma Pedidos SAP ya no pide otra contraseña.',
            'audience' => Announcement::AUDIENCE_ALL,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'is_pinned' => false,
            ...$overrides,
        ];
    }

    private function assertImage(string $path, int $width, int $height): void
    {
        Storage::disk('public')->assertExists($path);
        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
        $this->assertSame([$width, $height], [$size[0], $size[1]]);
    }
}
