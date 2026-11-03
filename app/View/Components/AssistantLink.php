<?php

namespace App\View\Components;

use App\Assistant\AssistantSwitch;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * «Asistente»: the link to /asistente, only while the assistant is on
 * (App\Assistant\AssistantSwitch) for a signed-in account. `band` is the
 * outlined link of the brand bar of secondary pages; `menu` is an item of
 * the home screen's user menu. Renders nothing otherwise, so pages can
 * include it unconditionally.
 */
class AssistantLink extends Component
{
    public function __construct(public readonly string $variant = 'band') {}

    public function shouldRender(): bool
    {
        return Auth::user() instanceof User && app(AssistantSwitch::class)->enabled();
    }

    public function render(): View
    {
        return view('components.assistant-link');
    }
}
