<?php

namespace App\Announcements;

use App\Models\Announcement;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Checks and stores announcement images on the "public" disk under
 * announcements/{id}/. PNG, JPEG and WebP of up to 2 MB are accepted and
 * re-encoded with GD as a 16:9 WebP (center crop, at most 1280 × 720).
 *
 * SVG is never accepted: an image that can carry script has no place in a
 * message anyone may be shown. Re-encoding also drops any metadata.
 */
class AnnouncementImageStore
{
    public const MAX_KILOBYTES = 2048;

    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public const WIDTH = 1280;

    public const HEIGHT = 720;

    /** Refuse to decode pictures larger than this (decompression bombs). */
    public const MAX_PIXELS = 40_000_000;

    public const WEBP_QUALITY = 82;

    private const TYPES = [
        'png' => IMAGETYPE_PNG,
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'webp' => IMAGETYPE_WEBP,
    ];

    /**
     * What is wrong with the file, in Spanish, or null when it can be used.
     */
    public function problem(UploadedFile $file): ?string
    {
        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            return 'La imagen pesa más de 2 MB.';
        }

        $expected = self::TYPES[strtolower($file->getClientOriginalExtension())] ?? null;
        $size = @getimagesize($file->getRealPath());

        if ($expected === null || $size === false || $size[2] !== $expected) {
            return 'El archivo no es una imagen PNG, JPG o WebP válida.';
        }

        if ($size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return 'La imagen es demasiado grande. Usa una de menos de 40 megapíxeles.';
        }

        return null;
    }

    /**
     * Converts the file and returns its path. The caller persists the path,
     * then deletes the one it replaced with {@see delete()}.
     */
    public function store(Announcement $announcement, UploadedFile $file): string
    {
        if (! $announcement->exists) {
            throw new InvalidArgumentException('Save the announcement before storing its image.');
        }

        if (($problem = $this->problem($file)) !== null) {
            throw new InvalidArgumentException($problem);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The image could not be decoded.');
        }

        $webp = $this->encode($this->orient($source, $file));
        $path = Announcement::IMAGE_DIR.'/'.$announcement->id.'/'.Str::lower(Str::random(16)).'.webp';

        Storage::disk('public')->put($path, $webp);

        return $path;
    }

    /**
     * Deletes an uploaded image; anything outside the announcements folder
     * is left alone.
     */
    public function delete(?string $path): void
    {
        if ($path !== null && str_starts_with($path, Announcement::IMAGE_DIR.'/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Center crop to 16:9, then scale down (never up) to fit 1280 × 720.
     */
    private function encode(GdImage $source): string
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width * 9 > $height * 16) {
            $cropHeight = $height;
            $cropWidth = max(1, (int) round($height * 16 / 9));
        } else {
            $cropWidth = $width;
            $cropHeight = max(1, (int) round($width * 9 / 16));
        }

        $outWidth = min(self::WIDTH, $cropWidth);
        $outHeight = max(1, min(self::HEIGHT, (int) round($outWidth * 9 / 16)));

        $target = imagecreatetruecolor($outWidth, $outHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));

        imagecopyresampled(
            $target, $source,
            0, 0,
            intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2),
            $outWidth, $outHeight,
            $cropWidth, $cropHeight,
        );

        ob_start();
        $written = imagewebp($target, null, self::WEBP_QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            throw new RuntimeException('The image could not be encoded as WebP.');
        }

        return $bytes;
    }

    /**
     * Phone photos store their rotation in EXIF; apply it so the picture is
     * not shown sideways once the metadata is dropped.
     */
    private function orient(GdImage $image, UploadedFile $file): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
