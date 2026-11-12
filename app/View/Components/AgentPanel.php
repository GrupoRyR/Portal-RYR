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
 * The page carries no token. The link is the plain `<agent>/panel/abrir`
 * and the launcher (the app's own copy, public/js/agente-lanzador.js, pinned
 * by a test) asks POST /agente/token (`data-agente-token-url`, with the
 * page's `csrf-token` meta) when the person clicks, so the button keeps
 * working on a page left open all day and no token sits in the HTML, in a
 * cache or in the saved offline copy. The launcher takes the agent's origin
 * from `data-agente-origen`.
 */
class AgentPanel extends Component
{
    public function shouldRender(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ! request()->routeIs('assistant.*')
            && AgentSettings::active()
            && PanelToken::eligible($user);
    }

    public function render(): View
    {
        $user = Auth::user();
        $slug = $user instanceof User ? $user->company?->slug : null;
        $script = public_path('js/agente-lanzador.js');

        return view('components.agent-panel', [
            'origin' => AgentSettings::origin(),
            'tokenUrl' => route('agent.token', absolute: false),
            // The launcher knows these two brands and falls back to neutral.
            'brand' => in_array($slug, ['segurex', 'derivados'], true) ? $slug : 'neutral',
            'script' => asset('js/agente-lanzador.js').'?v='.(is_file($script) ? filemtime($script) : 0),
        ]);
    }
}
