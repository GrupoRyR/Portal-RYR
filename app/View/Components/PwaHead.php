<?php

namespace App\View\Components;

use App\Models\User;
use App\Pwa\PwaIcons;
use App\Pwa\PwaIconSet;
use App\Pwa\WebManifest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * Install metadata of the page <head>: the manifest link, the Apple touch
 * icon and title (iOS reads these instead of the manifest) and the browser
 * bar color.
 */
class PwaHead extends Component
{
    public readonly PwaIconSet $icons;

    public readonly string $title;

    /** --brand-dark of the company; null keeps the neutral grounds per scheme. */
    public readonly ?string $themeColor;

    public function __construct(PwaIcons $icons)
    {
        $user = Auth::user();
        $company = $user instanceof User ? $user->company : null;

        $this->icons = $icons->for($company);
        $this->title = $company?->short_name ?: ($company?->name ?: (string) config('app.name'));
        $this->themeColor = $company === null ? null : WebManifest::themeColor($company);
    }

    public function render(): View
    {
        return view('components.pwa-head');
    }
}
