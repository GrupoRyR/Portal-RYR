<?php

namespace App\View\Components;

use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The company mark for headers (brand manual SGX/SDC): the logo uploaded in
 * Admin › Empresas, as an image with the company name as alt text.
 * - "Logo oscuro" (e.g. the white Segurex logo) sits on --brand-dark;
 * - a "Logo claro" only (e.g. SDC, opaque white background) sits on a white
 *   pill and is never inverted;
 * - without logos: the icon or the initial, plus the name as text.
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
        $logoPath = $company?->logo_dark_path ?? $company?->logo_light_path;

        $this->name = $company?->name ?? (string) config('app.name');
        $this->initial = Str::upper(mb_substr($company?->short_name ?: $this->name, 0, 1));
        $this->iconUrl = $company?->brandFileUrl($company->icon_path);
        $this->logoUrl = $company?->brandFileUrl($logoPath);
        $this->onDark = $company?->logo_dark_path !== null;
        $this->logoSize = $company?->brandImageSize($logoPath);
    }

    public function render(): View
    {
        return view('components.brand-logo');
    }
}
