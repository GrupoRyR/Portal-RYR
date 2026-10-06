<?php

namespace App\View\Components;

use App\Branding\BrandPalette;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * Inline <style> with the --brand-* variables of the signed-in user's
 * company (neutral when signed out or without a company).
 */
class BrandStyle extends Component
{
    public readonly string $css;

    public function __construct(?Company $company = null)
    {
        $user = Auth::user();

        $company ??= $user instanceof User ? $user->company : null;

        // BrandPalette only ever emits validated "#RRGGBB" values, so the
        // view may print this without escaping.
        $this->css = BrandPalette::forCompany($company)->toCss();
    }

    public function render(): View
    {
        return view('components.brand-style');
    }
}
