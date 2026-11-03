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

    /**
     * Most pixels accepted (16 megapixels, 4000 × 4000). GD decodes into
     * 4 bytes per pixel, so this keeps a decode near 64 MB, inside the
     * 128 MB memory_limit of shared hosting. A 12 MP phone photo fits; a
     * 48 MP one has to be reduced before it is uploaded.
     */
    public const MAX_PIXELS = 16_000_000;

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

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return 'La foto tiene demasiados megapíxeles (máximo '.(self::MAX_PIXELS / 1_000_000).'). Redúcela antes de subirla.';
        }

        return null;
    }

    /**
     * Saves the photo, re-encoded, and returns its path.
     *
     * @throws InvalidArgumentException When GD cannot decode the file.
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

        // Scale first and rotate the small copy: each step reassigns $image,
        // so the bigger bitmap is freed before the next one is made.
        $orientation = $extension === 'jpg' ? self::orientationOf((string) file_get_contents($file->getRealPath())) : 1;
        $image = $this->scaled($image);
        $image = $this->upright($image, $orientation);

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
    private function upright(GdImage $image, int $orientation): GdImage
    {
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $degrees === 0 ? $image : (imagerotate($image, $degrees, 0) ?: $image);
    }

    /**
     * The EXIF orientation (1-8) of a JPEG, or 1 when there is none. Read
     * from the APP1 segment directly, so the PHP exif extension (not
     * always installed) is not needed.
     */
    public static function orientationOf(string $jpeg): int
    {
        $length = strlen($jpeg);
        $offset = 2;

        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF") {
            $marker = ord($jpeg[$offset + 1]);

            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $size = unpack('n', substr($jpeg, $offset + 2, 2))[1];

            if ($marker === 0xE1 && substr($jpeg, $offset + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($jpeg, $offset + 10, max(0, $size - 8)));
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 14 || ! in_array(substr($tiff, 0, 2), ['II', 'MM'], true)) {
            return 1;
        }

        $short = substr($tiff, 0, 2) === 'II' ? 'v' : 'n';
        $long = $short === 'v' ? 'V' : 'N';
        $ifd = unpack($long, substr($tiff, 4, 4))[1];

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = unpack($short, substr($tiff, $ifd, 2))[1];

        for ($i = 0; $i < $entries; $i++) {
            $entry = substr($tiff, $ifd + 2 + $i * 12, 12);

            if (strlen($entry) === 12 && unpack($short, $entry)[1] === 0x0112) {
                $value = unpack($short, substr($entry, 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
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
