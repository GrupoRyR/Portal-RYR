<?php

namespace App\View\Components;

use App\Agent\AgentSettings;
use App\Agent\PanelToken;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * The agent's floating button (docs/agente-conexion.md): it replaces the old
 * «Asistente» button on the pages that opt in (layouts.app `assistant`),
 * and is drawn only when everything is in place:
 *
 * - AGENT_URL and AGENT_PANEL_KEY are valid ({@see AgentSettings});
 * - a signed-in account the agent accepts ({@see PanelToken::eligible()}):
 *   not an emergency or guest account, with an active mapped company;
 * - not on /asistente (the announcement drafts), which keeps its own forms.
 *
 * The link carries the token in the URL fragment (`#t=`), which browsers
 * never send to any server, so it is in no access log. The launcher is the
 * app's own copy (public/js/agente-lanzador.js, pinned by a test) and takes
 * the agent's origin from `data-agente-origen`.
 */
class AgentPanel extends Component
{
    private ?string $token = null;

    public function shouldRender(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || request()->routeIs('assistant.*')) {
            return false;
        }

        $this->token = PanelToken::for($user);

        return $this->token !== null;
    }

    public function render(): View
    {
        $user = Auth::user();
        $slug = $user instanceof User ? $user->company?->slug : null;
        $script = public_path('js/agente-lanzador.js');

        return view('components.agent-panel', [
            'origin' => AgentSettings::origin(),
            'token' => $this->token,
            // The launcher knows these two brands and falls back to neutral.
            'brand' => in_array($slug, ['segurex', 'derivados'], true) ? $slug : 'neutral',
            'script' => asset('js/agente-lanzador.js').'?v='.(is_file($script) ? filemtime($script) : 0),
        ]);
    }
}
