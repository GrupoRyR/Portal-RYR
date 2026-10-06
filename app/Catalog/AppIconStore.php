<?php

namespace App\Catalog;

use App\Branding\SvgLogo;
use App\Models\App;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Checks and stores app icons (brief §4b) on the "public" disk under
 * icons/apps/{slug}/: an SVG with a square viewBox (sanitized before it is
 * stored) or a square PNG/WebP of at least 192 px.
 */
class AppIconStore
{
    public const MAX_KILOBYTES = 512;

    public const MIN_PIXELS = 192;

    public const EXTENSIONS = ['svg', 'png', 'webp'];

    /** Upload variant => app attribute. */
    public const VARIANTS = [
        'light' => 'icon_path',
        'dark' => 'icon_dark_path',
    ];

    /**
     * What is wrong with the file, in Spanish, or null when it is a usable
     * icon. Size and extension are checked by the form rules first.
     */
    public function problem(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'svg') {
            $clean = SvgLogo::sanitize((string) file_get_contents($file->getRealPath()));

            if ($clean === null) {
                return 'El SVG no es válido o no se pudo limpiar. Exporta el ícono de nuevo como SVG.';
            }

            return self::hasSquareViewBox($clean)
                ? null
                : 'El SVG debe tener un viewBox cuadrado, por ejemplo 0 0 48 48.';
        }

        $size = @getimagesize($file->getRealPath());
        $expected = ['png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$extension] ?? null;

        if ($size === false || $expected === null || $size[2] !== $expected) {
            return 'El archivo no es una imagen PNG o WebP válida.';
        }

        if ($size[0] !== $size[1] || $size[0] < self::MIN_PIXELS) {
            return 'El ícono debe ser cuadrado de al menos '.self::MIN_PIXELS.' px.';
        }

        return null;
    }

    /**
     * Saves the file and returns its path. The caller persists the path on
     * the app, then calls {@see deleteReplaced()} with the old one.
     */
    public function store(string $slug, string $variant, UploadedFile $file): string
    {
        if (! array_key_exists($variant, self::VARIANTS)) {
            throw new InvalidArgumentException("Unknown icon variant [{$variant}].");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = App::ICON_DIR.'/'.$slug.'/'.$variant.'-'.Str::lower(Str::random(12)).'.'.$extension;

        if ($extension === 'svg') {
            // Validation already proved it sanitizes; store the clean markup.
            $clean = SvgLogo::sanitize((string) file_get_contents($file->getRealPath()))
                ?? throw new InvalidArgumentException('The SVG could not be sanitized.');

            Storage::disk('public')->put($path, $clean);
        } else {
            Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
        }

        return $path;
    }

    /**
     * Deletes an icon the app no longer uses. Bundled icons (public/) are
     * never touched.
     */
    public function deleteReplaced(App $app, ?string $oldPath, ?string $newPath): void
    {
        if ($oldPath !== null && $oldPath !== $newPath && $app->isUploadedIcon($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private static function hasSquareViewBox(string $svg): bool
    {
        $number = '([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)';
        $pattern = '/<svg\b[^>]*\bviewBox\s*=\s*["\']\s*'.$number.'[\s,]+'.$number.'[\s,]+'.$number.'[\s,]+'.$number.'\s*["\']/i';

        if (preg_match($pattern, $svg, $match) !== 1) {
            return false;
        }

        $width = (float) $match[3];
        $height = (float) $match[4];

        return $width > 0 && abs($width - $height) < 0.0001;
    }
}
