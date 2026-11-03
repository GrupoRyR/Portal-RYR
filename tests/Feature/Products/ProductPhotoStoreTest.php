<?php

namespace Tests\Feature\Products;

use App\Products\ProductPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ProductPhotoStoreTest extends TestCase
{
    private ProductPhotoStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->store = new ProductPhotoStore;
    }

    public function test_a_jpeg_png_and_webp_photo_are_usable(): void
    {
        foreach (['a.jpg', 'a.jpeg', 'a.png', 'a.webp'] as $name) {
            $this->assertNull($this->store->problem($this->image($name, 400, 300)), $name);
        }
    }

    public function test_a_file_that_is_not_an_image_is_refused_even_with_an_image_extension(): void
    {
        $fake = UploadedFile::fake()->createWithContent('photo.png', '<?php echo 1;');

        $this->assertStringContainsString('JPG, PNG o WebP', (string) $this->store->problem($fake));
    }

    public function test_an_extension_that_disagrees_with_the_content_is_refused(): void
    {
        $source = $this->image('x.jpg', 300, 300);
        $jpegNamedPng = UploadedFile::fake()->createWithContent('photo.png', file_get_contents($source->getRealPath()));

        $this->assertNotNull($this->store->problem($jpegNamedPng));
    }

    public function test_svg_is_not_accepted_for_photos(): void
    {
        $svg = UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"/>');

        $this->assertNotNull($this->store->problem($svg));
    }

    public function test_a_photo_that_is_too_small_is_refused(): void
    {
        $this->assertStringContainsString((string) ProductPhotoStore::MIN_PIXELS, (string) $this->store->problem($this->image('tiny.jpg', 100, 100)));
    }

    public function test_a_photo_is_stored_under_the_company_folder_and_re_encoded(): void
    {
        $path = $this->store->store(7, $this->image('Camara Ñandú.JPEG', 400, 300));

        $this->assertMatchesRegularExpression('#^products/7/[a-z0-9]{12}\.jpg$#', $path);
        Storage::disk('public')->assertExists($path);

        $size = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
    }

    public function test_re_encoding_drops_extra_bytes_hidden_after_the_image(): void
    {
        $source = $this->image('x.png', 300, 300);
        $original = file_get_contents($source->getRealPath());
        $upload = UploadedFile::fake()->createWithContent('x.png', $original.'<?php system($_GET["c"]); ?>');

        $path = $this->store->store(1, $upload);

        $this->assertStringNotContainsString('<?php', Storage::disk('public')->get($path));
    }

    public function test_a_large_photo_is_scaled_down_keeping_its_proportions(): void
    {
        $path = $this->store->store(1, $this->image('big.png', 2400, 1200));

        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame([ProductPhotoStore::MAX_SIDE, ProductPhotoStore::MAX_SIDE / 2], [$size[0], $size[1]]);
    }

    public function test_a_replaced_photo_is_deleted_but_the_new_one_is_kept(): void
    {
        $old = $this->store->store(1, $this->image('old.jpg', 300, 300));
        $new = $this->store->store(1, $this->image('new.jpg', 300, 300));

        $this->store->deleteReplaced($old, $new);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_the_same_path_is_never_deleted_as_replaced(): void
    {
        $path = $this->store->store(1, $this->image('a.jpg', 300, 300));

        $this->store->deleteReplaced($path, $path);

        Storage::disk('public')->assertExists($path);
    }

    public function test_only_files_inside_the_products_folder_can_be_deleted(): void
    {
        Storage::disk('public')->put('brand/segurex/logo.png', 'logo');
        Storage::disk('public')->put('products-evil/a.jpg', 'x');

        $this->store->delete('brand/segurex/logo.png');
        $this->store->delete('products/../brand/segurex/logo.png');
        $this->store->delete('products-evil/a.jpg');
        $this->store->delete(null);

        Storage::disk('public')->assertExists('brand/segurex/logo.png');
        Storage::disk('public')->assertExists('products-evil/a.jpg');
    }

    public function test_a_photo_with_too_many_pixels_is_refused_before_it_is_decoded(): void
    {
        // Only the header is real: problem() must answer from it, without decoding.
        $heavy = UploadedFile::fake()->createWithContent('heavy.png', $this->pngHeader(5000, 4000));

        $this->assertStringContainsString('megapíxeles', (string) $this->store->problem($heavy));
    }

    public function test_a_photo_exactly_at_the_pixel_cap_is_accepted(): void
    {
        $edge = UploadedFile::fake()->createWithContent('edge.png', $this->pngHeader(4000, 4000));

        $this->assertNull($this->store->problem($edge));
    }

    public function test_a_photo_with_a_side_over_the_limit_is_refused(): void
    {
        $long = UploadedFile::fake()->createWithContent('long.png', $this->pngHeader(7000, 300));

        $this->assertStringContainsString('por lado', (string) $this->store->problem($long));
    }

    public function test_a_file_gd_cannot_decode_is_refused_when_stored(): void
    {
        $broken = UploadedFile::fake()->createWithContent('broken.png', $this->pngHeader(400, 400).'not really image data');

        $this->assertNull($this->store->problem($broken), 'The header alone looks fine.');

        $this->expectException(InvalidArgumentException::class);

        $this->store->store(1, $broken);
    }

    public function test_exif_orientations_6_and_8_turn_a_landscape_photo_upright(): void
    {
        foreach ([6, 8] as $orientation) {
            $path = $this->store->store(1, $this->jpegWithOrientation(400, 200, $orientation));
            $size = getimagesizefromstring(Storage::disk('public')->get($path));

            $this->assertSame([200, 400], [$size[0], $size[1]], "Orientation {$orientation}");
        }
    }

    public function test_orientation_1_and_a_photo_without_exif_keep_their_shape(): void
    {
        $upright = $this->store->store(1, $this->jpegWithOrientation(400, 200, 1));
        $plain = $this->store->store(1, $this->image('plain.jpg', 400, 200));

        foreach ([$upright, $plain] as $path) {
            $size = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame([400, 200], [$size[0], $size[1]]);
        }
    }

    public function test_orientation_3_turns_the_photo_around_without_changing_its_shape(): void
    {
        $path = $this->store->store(1, $this->jpegWithOrientation(400, 200, 3));
        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame([400, 200], [$size[0], $size[1]]);
    }

    /**
     * A PNG signature and IHDR chunk claiming the given size: enough for
     * getimagesize(), far too little to decode.
     */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    /**
     * A real JPEG with an EXIF APP1 segment that only says its orientation.
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation): UploadedFile
    {
        $source = $this->image('source.jpg', $width, $height);
        $bytes = (string) file_get_contents($source->getRealPath());

        $exif = "Exif\0\0"."MM\x00\x2a\x00\x00\x00\x08".pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0).pack('N', 0);
        $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return UploadedFile::fake()->createWithContent('photo.jpg', substr($bytes, 0, 2).$segment.substr($bytes, 2));
    }

    private function image(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }
}
