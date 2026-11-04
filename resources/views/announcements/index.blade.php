{{--
    «Ver todos los anuncios»: every announcement the user may see, pinned
    first. Same card as the home aside (x-announcements.card). No Livewire
    or Alpine; each item has an anchor (#a-{id}) for the phone banner.
    Liquid Glass (odd/tasks/liquid-glass.md G9): the list is rows of one
    g-card, its pager in the card's foot (resources/css/glass-pages.css).
--}}
<x-layouts.app title="Anuncios" glass assistant>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1>Anuncios</h1>
        </div>

        @if (session('status'))
            <p role="status" class="g-card g-page__flash">{{ session('status') }}</p>
        @endif

        @if ($cards->isEmpty())
            <p class="g-card g-page__empty">No hay anuncios por ahora.</p>
        @else
            <div class="g-card">
                <ol class="g-page__list">
                    @foreach ($cards as $card)
                        <li id="a-{{ $card->id }}" class="g-page__entry">
                            <x-announcements.card :card="$card" heading="h2" large />
                        </li>
                    @endforeach
                </ol>

                @if ($announcements->hasPages())
                    <nav aria-label="Páginas de anuncios" class="g-page__foot">
                        @if ($announcements->previousPageUrl())
                            <a href="{{ $announcements->previousPageUrl() }}" rel="prev" class="g-btn g-btn--small">
                                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Más recientes
                            </a>
                        @else
                            <span></span>
                        @endif
                        <span>Página {{ $announcements->currentPage() }} de {{ $announcements->lastPage() }}</span>
                        @if ($announcements->nextPageUrl())
                            <a href="{{ $announcements->nextPageUrl() }}" rel="next" class="g-btn g-btn--small">
                                Anteriores<x-home.icon name="chevron-right" stroke-width="2" class="size-[18px]" />
                            </a>
                        @else
                            <span></span>
                        @endif
                    </nav>
                @endif
            </div>
        @endif
    </main>
</x-layouts.app>
