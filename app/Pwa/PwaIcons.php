<?php

namespace App\Pwa;

use App\Models\Company;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The installed-app icons of a company, made with GD from its brand icon
 * (a 512 × 512 PNG, enforced by Administración → Empresas).
 *
 * The files are generated once, next to the brand icon, under
 * brand/{slug}/pwa/{icon-name}-{variant}.png on the "public" disk. A new
 * brand icon has a new random name, so its files are new too; the files of
 * the previous icon are deleted when the new ones are made.
 *
 * Without GD, an icon, or a readable PNG, the neutral portal icons are used.
 */
class PwaIcons
{
    /**
     * Variant => [size in px, full bleed]. Full-bleed icons have no
     * transparent pixels: launchers crop maskable icons to their own shape
     * and iOS paints transparent pixels of the touch icon black.
     */
    public const VARIANTS = [
        '192' => [192, false],
        '512' => [512, false],
        'maskable-512' => [512, true],
        'apple-180' => [180, true],
    ];

    /** Share of a full-bleed canvas the brand icon may use (W3C maskable safe zone). */
    private const SAFE_ZONE = 0.8;

    /** GD alpha (0 opaque … 127 transparent) under which a pixel counts as opaque. */
    private const OPAQUE_ALPHA = 16;

    public function for(?Company $company): PwaIconSet
    {
        $paths = $company === null ? null : $this->generated($company);

        if ($paths === null) {
            return PwaIconSet::portal();
        }

        $disk = Storage::disk('public');

        return new PwaIconSet(
            any192: $disk->url($paths['192']),
            any512: $disk->url($paths['512']),
            maskable512: $disk->url($paths['maskable-512']),
            apple180: $disk->url($paths['apple-180']),
        );
    }

    /**
     * Paths of the company's generated icons, making them when missing.
     *
     * @return array<string, string>|null
     */
    private function generated(Company $company): ?array
    {
        $source = $company->icon_path;

        if (! is_string($source) || $source === '') {
            return null;
        }

        $disk = Storage::disk('public');
        $directory = dirname($source).'/pwa';
        $name = pathinfo($source, PATHINFO_FILENAME);

        $paths = [];
        foreach (array_keys(self::VARIANTS) as $variant) {
            $paths[$variant] = "{$directory}/{$name}-{$variant}.png";
        }

        if (collect($paths)->every(fn (string $path) => $disk->exists($path))) {
            return $paths;
        }

        if (! function_exists('imagecreatefromstring') || ! $disk->exists($source)) {
            return null;
        }

        $image = @imagecreatefromstring((string) $disk->get($source));

        if ($image === false) {
            Log::warning('PWA icons could not be made from the company icon.', ['company' => $company->id]);

            return null;
        }

        imagepalettetotruecolor($image);

        // Files of a previous brand icon.
        foreach ($disk->files($directory) as $file) {
            if (! str_starts_with(basename($file), $name.'-')) {
                $disk->delete($file);
            }
        }

        foreach (self::VARIANTS as $variant => [$size, $fullBleed]) {
            $disk->put($paths[$variant], $this->render($image, $size, $fullBleed));
        }

        return $paths;
    }

    private function render(GdImage $source, int $size, bool $fullBleed): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if ($fullBleed) {
            imagefill($canvas, 0, 0, $this->background($source, $canvas));
            imagealphablending($canvas, true);
            $inner = (int) round($size * self::SAFE_ZONE);
            $offset = intdiv($size - $inner, 2);
        } else {
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            $inner = $size;
            $offset = 0;
        }

        imagecopyresampled($canvas, $source, $offset, $offset, 0, 0, $inner, $inner, imagesx($source), imagesy($source));

        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * The icon's own background (the pixel at the top center, inside any
     * rounded corner), so the full-bleed edge continues it seamlessly; white
     * when that pixel is transparent (a logo without a background).
     */
    private function background(GdImage $source, GdImage $canvas): int
    {
        $color = imagecolorsforindex($source, imagecolorat($source, intdiv(imagesx($source), 2), 1));

        return $color['alpha'] < self::OPAQUE_ALPHA
            ? imagecolorallocate($canvas, $color['red'], $color['green'], $color['blue'])
            : imagecolorallocate($canvas, 0xFF, 0xFF, 0xFF);
    }
}
