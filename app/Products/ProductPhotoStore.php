<?php

namespace App\Products;

use App\Models\Product;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Checks and stores product photos on the "public" disk under
 * products/{company id}/, the way App\Catalog\AppIconStore does for app
 * icons: the form rules check size and extension first, {@see problem()}
 * checks the content, and the caller persists the returned path and then
 * calls {@see deleteReplaced()} with the old one.
 *
 * Unlike icons, photos are always decoded and encoded again with GD: what
 * is stored is a fresh image, so metadata and any bytes hidden after the
 * image data never reach the disk.
 */
class ProductPhotoStore
{
    public const MAX_KILOBYTES = 2048;

    /** Shortest side accepted, in pixels. */
    public const MIN_PIXELS = 200;

    /** Longest side stored: larger photos are scaled down to it. */
    public const MAX_SIDE = 1200;

    /** Longest side accepted, so a tiny file cannot unpack into a huge bitmap. */
    private const MAX_INPUT_SIDE = 6000;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const TYPES = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
    ];

    /**
     * What is wrong with the file, in Spanish, or null when it is a usable
     * photo.
     */
    public function problem(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $size = @getimagesize($file->getRealPath());
        $expected = self::TYPES[$extension] ?? null;

        if ($size === false || $expected === null || $size[2] !== $expected) {
            return 'El archivo no es una imagen JPG, PNG o WebP válida.';
        }

        if (min($size[0], $size[1]) < self::MIN_PIXELS) {
            return 'La foto debe medir al menos '.self::MIN_PIXELS.' px de ancho y de alto.';
        }

        if (max($size[0], $size[1]) > self::MAX_INPUT_SIDE) {
            return 'La foto es demasiado grande. Redúcela a '.self::MAX_INPUT_SIDE.' px o menos por lado.';
        }

        return null;
    }

    /**
     * Saves the photo, re-encoded, and returns its path.
     */
    public function store(int $companyId, UploadedFile $file): string
    {
        $extension = match (self::TYPES[strtolower($file->getClientOriginalExtension())] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => throw new InvalidArgumentException('The photo type is not supported.'),
        };

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The photo could not be read.');
        }

        $image = $this->upright($image, $file, $extension);
        $image = $this->scaled($image);

        $path = Product::PHOTO_DIR.'/'.$companyId.'/'.Str::lower(Str::random(12)).'.'.$extension;

        Storage::disk('public')->put($path, $this->encode($image, $extension));

        return $path;
    }

    /**
     * Deletes a photo the product no longer uses.
     */
    public function deleteReplaced(?string $oldPath, ?string $newPath): void
    {
        if ($oldPath !== $newPath) {
            $this->delete($oldPath);
        }
    }

    /**
     * Deletes a stored photo. Only files inside the products folder are
     * ever touched.
     */
    public function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, Product::PHOTO_DIR.'/') && ! str_contains($path, '..')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Applies the EXIF orientation of a JPEG (phones store photos sideways
     * and rely on it); re-encoding drops the tag.
     */
    private function upright(GdImage $image, UploadedFile $file, string $extension): GdImage
    {
        if ($extension !== 'jpg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        if ($degrees === 0) {
            return $image;
        }

        return imagerotate($image, $degrees, 0) ?: $image;
    }

    private function scaled(GdImage $image): GdImage
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= self::MAX_SIDE) {
            return $image;
        }

        $scaled = $width >= $height
            ? imagescale($image, self::MAX_SIDE, -1)
            : imagescale($image, (int) round($width * self::MAX_SIDE / $height), self::MAX_SIDE);

        if (! $scaled instanceof GdImage) {
            throw new InvalidArgumentException('The photo could not be scaled.');
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        return $scaled;
    }

    private function encode(GdImage $image, string $extension): string
    {
        ob_start();

        match ($extension) {
            'png' => imagepng($image, null, 6),
            'webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };

        return (string) ob_get_clean();
    }
}
