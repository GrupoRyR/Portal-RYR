{{-- The agent's button (App\View\Components\AgentPanel). The token rides in the fragment, never in a query; the launcher is our own pinned copy and reads the agent's origin from data-agente-origen. Without JavaScript it is a plain link that opens the agent in a new tab. --}}
<div class="agent-anchor" data-agent-present>
    <script src="{{ $script }}" data-agente-origen="{{ $origin }}" defer></script>
    <a href="{{ $origin }}/panel/abrir#t={{ $token }}" data-agente-panel data-agente-marca="{{ $brand }}" target="_blank" rel="noopener">Preguntar al agente</a>
</div>
