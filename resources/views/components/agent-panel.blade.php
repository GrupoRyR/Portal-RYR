{{-- The agent's button (App\View\Components\AgentPanel). The token rides in the fragment, never in a query; the launcher is our own pinned copy and reads the agent's origin from data-agente-origen. Without JavaScript it is a plain link that opens the agent in a new tab. It is a <section data-offline-omit> so the service worker leaves it out of the saved home page, where its token would be stale. --}}
<section class="agent-anchor" data-agent-present data-offline-omit>
    <script src="{{ $script }}" data-agente-origen="{{ $origin }}" defer></script>
    <a href="{{ $origin }}/panel/abrir#t={{ $token }}" data-agente-panel data-agente-marca="{{ $brand }}" target="_blank" rel="noopener">Preguntar al agente</a>
</section>
