<?php

namespace App\Branding;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * The company logo a header shows (brand manual SGX/SDC): the "Logo oscuro"
 * for a dark background when its file exists, else the "Logo claro". Seeded
 * companies point at brand/logo-*.png before anyone uploads them; a path
 * without a file counts as no logo, so callers show the name instead.
 */
final readonly class CompanyLogo
{
    public function __construct(
        public string $path,
        public bool $onDark,
    ) {}

    public static function for(?Company $company): ?self
    {
        if ($company === null) {
            return null;
        }

        foreach ([[$company->logo_dark_path, true], [$company->logo_light_path, false]] as [$path, $onDark]) {
            if ($path !== null && Storage::disk('public')->exists($path)) {
                return new self($path, $onDark);
            }
        }

        return null;
    }
}
