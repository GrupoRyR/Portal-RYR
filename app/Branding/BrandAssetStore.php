<?php

namespace App\Branding;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores a company's logos and icon on the "public" disk under
 * brand/{slug}/ and deletes the file each upload replaces.
 */
class BrandAssetStore
{
    /**
     * Upload slot => company attribute.
     */
    public const SLOTS = [
        'logo-light' => 'logo_light_path',
        'logo-dark' => 'logo_dark_path',
        'icon' => 'icon_path',
    ];

    /**
     * Saves the file and returns its path. The caller persists the path on
     * the company, then calls {@see deleteReplaced()} with the old one.
     */
    public function store(Company $company, string $slot, UploadedFile $file): string
    {
        if (! array_key_exists($slot, self::SLOTS)) {
            throw new InvalidArgumentException("Unknown brand slot [{$slot}].");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = 'brand/'.$company->slug.'/'.$slot.'-'.Str::lower(Str::random(12)).'.'.$extension;

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

    public function deleteReplaced(?string $oldPath, ?string $newPath): void
    {
        if ($oldPath !== null && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
