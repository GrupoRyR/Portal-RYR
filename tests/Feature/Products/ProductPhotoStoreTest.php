<?php

namespace Tests\Feature\Products;

use App\Products\ProductPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function image(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }
}
