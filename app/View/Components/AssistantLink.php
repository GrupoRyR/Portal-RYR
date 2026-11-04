<?php

namespace App\View\Components;

use App\Assistant\AssistantSwitch;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * «Asistente»: the link to /asistente, only while the assistant is on
 * (App\Assistant\AssistantSwitch) for a signed-in account: an item of
 * the user menu of components/portal-header. Renders nothing otherwise, so
 * the header includes it unconditionally.
 */
class AssistantLink extends Component
{
    public function shouldRender(): bool
    {
        return Auth::user() instanceof User && app(AssistantSwitch::class)->enabled();
    }

    public function render(): View
    {
        return view('components.assistant-link');
    }
}
