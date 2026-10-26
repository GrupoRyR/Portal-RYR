{{--
    «Ver todos los anuncios»: every announcement the user may see, pinned
    first. Same card as the home aside (x-announcements.card). No Livewire
    or Alpine; each item has an anchor (#a-{id}) for the phone banner.
--}}
<x-layouts.app title="Anuncios" brand-band>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Anuncios</h1>
        </div>

        @if ($cards->isEmpty())
            <p class="rounded-card border border-line bg-surface px-5 py-8 text-center text-[0.9375rem] text-fg-muted">No hay anuncios por ahora.</p>
        @else
            <ol class="flex flex-col gap-4">
                @foreach ($cards as $card)
                    <li id="a-{{ $card->id }}" class="scroll-mt-6 rounded-card border border-line bg-surface p-5 target:border-brand-button md:p-6">
                        <x-announcements.card :card="$card" heading="h2" large />
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($announcements->hasPages())
            <nav aria-label="Páginas de anuncios" class="flex items-center justify-between gap-3 text-sm font-semibold">
                @if ($announcements->previousPageUrl())
                    <a href="{{ $announcements->previousPageUrl() }}" rel="prev" class="inline-flex min-h-touch items-center gap-1.5 rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">
                        <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Más recientes
                    </a>
                @else
                    <span></span>
                @endif
                <span class="text-fg-muted">Página {{ $announcements->currentPage() }} de {{ $announcements->lastPage() }}</span>
                @if ($announcements->nextPageUrl())
                    <a href="{{ $announcements->nextPageUrl() }}" rel="next" class="inline-flex min-h-touch items-center gap-1.5 rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">
                        Anteriores<x-home.icon name="chevron-right" stroke-width="2" class="size-[18px]" />
                    </a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
    </main>
</x-layouts.app>
