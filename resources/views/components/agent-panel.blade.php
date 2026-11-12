{{-- The agent's button (App\View\Components\AgentPanel). No token on the page: the launcher (our own pinned copy) POSTs data-agente-token-url, with the csrf-token meta, when the person clicks, and opens the agent's panel with the fresh token in the fragment. It reads the agent's origin from data-agente-origen. Without JavaScript the link just opens the agent in a new tab. It is a <section data-offline-omit> so the service worker leaves it out of the saved home page. --}}
<section class="agent-anchor" data-agent-present data-offline-omit>
    <script src="{{ $script }}" data-agente-origen="{{ $origin }}" data-agente-token-url="{{ $tokenUrl }}" defer></script>
    <a href="{{ $origin }}/panel/abrir" data-agente-panel data-agente-marca="{{ $brand }}" target="_blank" rel="noopener">Preguntar al agente</a>
</section>
