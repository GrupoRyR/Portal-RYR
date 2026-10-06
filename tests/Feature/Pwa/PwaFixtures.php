<?php

namespace Tests\Feature\Pwa;

/**
 * PNG bytes for the PWA icon tests, drawn with GD.
 */
final class PwaFixtures
{
    /**
     * A solid square of the given RGB color; with $transparentEdge the
     * outer 10 % is fully transparent (a logo without a background).
     *
     * @param  array{int, int, int}  $rgb
     */
    public static function png(int $width, int $height, array $rgb, bool $transparentEdge = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        $inset = $transparentEdge ? (int) round($width * 0.1) : 0;
        imagefilledrectangle($image, $inset, $inset, $width - 1 - $inset, $height - 1 - $inset, imagecolorallocate($image, ...$rgb));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
