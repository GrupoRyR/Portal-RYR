<?php

namespace App\View\Components;

use App\Branding\CompanyLogo;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * The company mark for headers (brand manual SGX/SDC): the logo uploaded in
 * Admin › Empresas, as an image with the company name as alt text.
 * - "Logo oscuro" (e.g. the white Segurex logo) sits on --brand-dark;
 * - a "Logo claro" only (e.g. SDC, opaque white background) sits on a white
 *   pill and is never inverted;
 * - without logos (or when the file is missing): the icon or the initial,
 *   plus the name as text.
 * On a --brand-dark band (onBand) the dark logo goes straight on it.
 * The slot is a caption shown after the mark (e.g. "Portal de aplicaciones").
 */
class BrandLogo extends Component
{
    public readonly string $name;

    public readonly string $initial;

    /** Short name for compact lists (the full name when there is none). */
    public readonly string $shortName;

    public readonly ?string $iconUrl;

    public readonly ?string $logoUrl;

    /** True when the logo is meant for a dark background. */
    public readonly bool $onDark;

    /** @var array{0: int, 1: int}|null */
    public readonly ?array $logoSize;

    /**
     * @param  bool  $onBand  Drawn on a --brand-dark band (.brand-band): the
     *                        dark logo needs no chip of its own there.
     * @param  bool  $compact  For lists of several companies (sign-in): without
     *                         a logo, a small icon and the short name.
     */
    public function __construct(?Company $company = null, public bool $onBand = false, public bool $compact = false)
    {
        $user = Auth::user();

        $company ??= $user instanceof User ? $user->company : null;
        $logo = CompanyLogo::for($company);
        $logoPath = $logo?->path;

        $this->name = Company::displayName($company);
        $this->initial = Company::displayInitial($company);
        $this->shortName = $company?->short_name ?: $this->name;
        $this->iconUrl = $company?->brandFileUrl($company->icon_path);
        $this->logoUrl = $company?->brandFileUrl($logoPath);
        $this->onDark = $logo?->onDark ?? false;
        // Only asked once the file exists, so its cached size is never a
        // "missing" null for a fixed path uploaded later (seeded paths).
        $this->logoSize = $company?->brandImageSize($logoPath);
    }

    public function render(): View
    {
        return view('components.brand-logo');
    }
}
