<?php

namespace App\View\Components;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Component;

/**
 * The company mark for headers (brand manual SGX/SDC): the logo uploaded in
 * Admin › Empresas, as an image with the company name as alt text.
 * - "Logo oscuro" (e.g. the white Segurex logo) sits on --brand-dark;
 * - a "Logo claro" only (e.g. SDC, opaque white background) sits on a white
 *   pill and is never inverted;
 * - without logos (or when the file is missing): the icon or the initial,
 *   plus the name as text.
 * The slot is a caption shown after the mark (e.g. "Portal de aplicaciones").
 */
class BrandLogo extends Component
{
    public readonly string $name;

    public readonly string $initial;

    public readonly ?string $iconUrl;

    public readonly ?string $logoUrl;

    /** True when the logo is meant for a dark background. */
    public readonly bool $onDark;

    /** @var array{0: int, 1: int}|null */
    public readonly ?array $logoSize;

    public function __construct(?Company $company = null)
    {
        $user = Auth::user();

        $company ??= $user instanceof User ? $user->company : null;
        $darkPath = self::storedPath($company?->logo_dark_path);
        $logoPath = $darkPath ?? self::storedPath($company?->logo_light_path);

        $this->name = Company::displayName($company);
        $this->initial = Company::displayInitial($company);
        $this->iconUrl = $company?->brandFileUrl($company->icon_path);
        $this->logoUrl = $company?->brandFileUrl($logoPath);
        $this->onDark = $darkPath !== null;
        // Only asked once the file exists, so its cached size is never a
        // "missing" null for a fixed path uploaded later (seeded paths).
        $this->logoSize = $company?->brandImageSize($logoPath);
    }

    /**
     * The path when its file is on the "public" disk. Seeded companies point
     * at brand/logo-*.png before anyone uploads them: those show the name.
     */
    private static function storedPath(?string $path): ?string
    {
        return $path !== null && Storage::disk('public')->exists($path) ? $path : null;
    }

    public function render(): View
    {
        return view('components.brand-logo');
    }
}
