{{--
    Home screen (docs/diseno/Main, Tablet, PhoneHome, PhoneDark, PhoneSearch
    and PhoneEmpty). Announcements: a card in the desktop aside, a banner
    with the top one on phone and tablet. $page is an App\View\Home\HomePage: no queries here.
    Works without JavaScript; resources/js/home.js adds instant search and
    the favorite toggle. No Livewire or Alpine on this page.
--}}
@php
    /** @var \App\View\Home\HomePage $page */
    $searching = $page->isSearching();
@endphp
<x-layouts.app title="Inicio" viewport-fit :data-server-search="$searching ? 'true' : 'false'">
    <x-slot:head>
        @vite('resources/js/home.js')
    </x-slot:head>

    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <header class="md:border-b md:border-line md:bg-surface">
        <div class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-x-8 gap-y-3 px-4 pt-5 pb-3 md:h-[72px] md:flex-nowrap md:px-8 md:py-0 lg:px-10">
            <a href="{{ route('home') }}" class="order-1 flex min-w-0 flex-1 items-center gap-3 md:flex-none md:shrink-0 xl:w-[300px]">
                @if ($page->companyIconUrl)
                    <img src="{{ $page->companyIconUrl }}" alt="" width="36" height="36" class="size-9 shrink-0 rounded-[10px] object-contain">
                @else
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-brand-button font-display text-lg font-bold text-white">{{ $page->companyInitial }}</span>
                @endif
                <span class="flex min-w-0 flex-col leading-tight">
                    <span class="truncate font-display text-lg font-bold">{{ $page->companyName }}</span>
                    <span class="hidden text-xs text-fg-muted md:block">Portal de aplicaciones</span>
                </span>
            </a>

            @if ($page->hasApps())
                <form role="search" method="GET" action="{{ route('home') }}" class="order-3 basis-full md:order-2 md:mx-auto md:max-w-[560px] md:flex-1 md:basis-auto" data-search-form>
                    <label for="buscar" class="sr-only">Buscar aplicaciones</label>
                    <div class="flex h-12 items-center gap-2.5 rounded-[14px] border border-line bg-surface pr-2 pl-3.5 focus-within:border-brand-button focus-within:ring-1 focus-within:ring-brand-button md:h-11 md:rounded-control md:bg-subtle">
                        <x-home.icon name="search" stroke-width="2" class="size-[18px] text-fg-muted" />
                        <input id="buscar" name="q" type="search" value="{{ $page->query }}" maxlength="{{ \App\Catalog\AppSearch::MAX_LENGTH }}" placeholder="Buscar aplicaciones" autocomplete="off" enterkeyhint="search" aria-describedby="resultados" class="h-full min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-fg-muted md:text-[0.9375rem]">
                        <kbd class="hidden rounded-md border border-line-strong bg-surface px-2 py-0.5 font-sans text-xs text-fg-muted md:inline" title="Pulsa / para buscar">/</kbd>
                    </div>
                </form>
            @endif

            <details id="cuenta" class="relative order-2 ml-auto md:order-3 md:shrink-0 xl:flex xl:w-[300px] xl:justify-end" data-account>
                <summary class="flex size-touch cursor-pointer list-none items-center justify-center rounded-full bg-brand-tint text-[0.9375rem] font-bold text-brand-ink [&::-webkit-details-marker]:hidden">
                    <span aria-hidden="true">{{ $page->userInitials }}</span>
                    <span class="sr-only">Cuenta de {{ $page->userName }}</span>
                </summary>
                <div class="absolute top-full right-0 z-40 mt-2 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-card border border-line bg-surface p-2 shadow-card">
                    <div class="flex flex-col px-3 py-2">
                        <span class="font-semibold">{{ $page->userName }}</span>
                        @if ($page->userEmail)
                            <span class="truncate text-[0.8125rem] text-fg-muted">{{ $page->userEmail }}</span>
                        @endif
                    </div>
                    @if ($page->canAdminister)
                        <a href="{{ route('admin.index') }}" class="flex min-h-touch items-center gap-3 rounded-control px-3 text-sm font-semibold hover:bg-subtle">
                            <x-home.icon name="settings" class="size-[18px] text-fg-muted" />Administración
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex min-h-touch w-full cursor-pointer items-center gap-3 rounded-control px-3 text-left text-sm font-semibold hover:bg-subtle">
                            <x-home.icon name="logout" class="size-[18px] text-fg-muted" />Cerrar sesión
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </header>

    <div class="mx-auto flex max-w-[1440px] flex-col gap-10 px-4 pt-2 pb-[calc(6rem+env(safe-area-inset-bottom))] md:px-8 md:pt-8 md:pb-10 lg:px-10 lg:pt-10 xl:flex-row">
        <main id="contenido" class="flex min-w-0 flex-1 flex-col gap-8 md:gap-10">
            <div class="flex flex-col gap-1 md:gap-1.5">
                <p class="text-sm font-semibold text-brand-ink">{{ $page->dateLine }}</p>
                <h1 class="text-[1.75rem] leading-[1.05] font-bold tracking-[-0.025em] md:text-[2.125rem] lg:text-[2.5rem]">{{ $page->greeting() }}</h1>
                @if ($page->hasApps())
                    <p class="text-[0.9375rem] text-fg-muted md:text-base">{{ $page->availability() }}</p>
                    <p id="resultados" aria-live="polite" class="mt-2 text-sm font-semibold empty:mt-0">@if ($searching){{ $page->resultSummary() }}@endif</p>
                    @if ($searching)
                        <a href="{{ route('home') }}" class="inline-flex min-h-touch items-center self-start text-sm font-semibold text-brand-ink underline-offset-4 hover:underline">Ver todas las aplicaciones</a>
                    @endif
                @endif
            </div>

            @if ($top = $page->topAnnouncement())
                <x-announcements.banner :card="$top" data-announcement-banner class="xl:hidden" />
            @endif

            @if (! $page->hasApps())
                <section aria-labelledby="sin-apps" class="flex flex-col items-center gap-3.5 rounded-[20px] border border-line bg-surface px-6 py-7 text-center md:max-w-md md:self-start">
                    <span class="flex size-22 items-center justify-center rounded-full bg-brand-tint text-brand-ink">
                        <x-home.icon name="apps-add" stroke-width="1.6" class="size-11" />
                    </span>
                    <h2 id="sin-apps" class="text-xl leading-snug font-bold">Aún no tienes aplicaciones asignadas</h2>
                    <p class="text-[0.9375rem] leading-normal text-fg-soft">Solicítalas a TI. Cuando te den acceso, aparecerán aquí.</p>
                </section>
            @else

                <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:gap-10">
                    <section id="favoritos" aria-labelledby="favoritos-titulo" data-section class="flex min-w-0 scroll-mt-6 flex-col gap-3 md:gap-3.5 lg:shrink" @if ($searching && $page->favorites === []) hidden @endif>
                        <h2 id="favoritos-titulo" class="font-sans text-[0.8125rem] font-bold tracking-[0.06em] text-fg-muted uppercase">Favoritos</h2>
                        <p data-favorites-hint class="max-w-xs text-sm text-fg-muted" @if ($page->favorites !== []) hidden @endif>Toca la estrella de una aplicación para fijarla aquí.</p>
                        <ul data-list class="grid grid-cols-2 gap-3 md:flex md:flex-wrap md:gap-4">
                            @foreach ($page->favorites as $card)
                                <x-home.app-card :card="$card" :query="$page->query" />
                            @endforeach
                        </ul>
                    </section>

                    @if ($page->recents !== [])
                        <section id="recientes" aria-labelledby="recientes-titulo" class="flex min-w-0 flex-col gap-3 md:gap-3.5 lg:min-w-[280px] lg:flex-1">
                            <h2 id="recientes-titulo" class="font-sans text-[0.8125rem] font-bold tracking-[0.06em] text-fg-muted uppercase">Recientes</h2>
                            <ul class="flex flex-col rounded-card border border-line bg-surface p-1.5">
                                @foreach ($page->recents as $card)
                                    <li>
                                        <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="flex min-h-touch items-center gap-3 rounded-control px-3 py-2 hover:bg-ground">
                                            @if ($card->iconUrl)
                                                <picture class="shrink-0">
                                                    @if ($card->iconDarkUrl)
                                                        <source srcset="{{ $card->iconDarkUrl }}" media="(prefers-color-scheme: dark)">
                                                    @endif
                                                    <img src="{{ $card->iconUrl }}" alt="" width="36" height="36" class="size-9 rounded-[10px]">
                                                </picture>
                                            @else
                                                <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-(--app-accent) font-display font-bold text-white" style="--app-accent: {{ $card->accentColor }}">{{ $card->initial }}</span>
                                            @endif
                                            <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $card->name }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                </div>

                <div class="flex flex-col gap-8 md:flex-row md:flex-wrap md:gap-x-10 md:gap-y-7">
                    @foreach ($page->categories as $group)
                        <section aria-labelledby="categoria-{{ $group->slug }}" data-section data-category="{{ $group->slug }}" class="flex min-w-0 max-w-full flex-col gap-3 md:gap-3.5" @if ($group->isEmpty()) hidden @endif>
                            <h2 id="categoria-{{ $group->slug }}" class="font-sans text-[0.8125rem] font-bold tracking-[0.06em] text-fg-muted uppercase">{{ $group->name }}</h2>
                            <ul data-list class="grid grid-cols-2 gap-3 md:flex md:flex-wrap md:gap-4">
                                @foreach ($group->cards as $card)
                                    <x-home.app-card :card="$card" :query="$page->query" />
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            @endif
        </main>

        <aside class="flex flex-col gap-5 xl:w-[340px] xl:shrink-0">
            {{-- Desktop only; phones and tablets get the banner above. --}}
            @if ($page->announcements !== [])
                <section aria-labelledby="anuncios" data-announcements class="hidden flex-col gap-4 rounded-card border border-line bg-surface p-5 xl:flex">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="anuncios" class="text-xl font-bold">Anuncios</h2>
                        <a href="{{ route('announcements.index') }}" class="-my-3 inline-flex min-h-touch items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Ver todos<span class="sr-only"> los anuncios</span></a>
                    </div>
                    <ul class="flex flex-col divide-y divide-line">
                        @foreach ($page->announcements as $announcement)
                            <li class="py-4 first:pt-0 last:pb-0">
                                <x-announcements.card :card="$announcement" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section aria-labelledby="m365" class="flex flex-col gap-3 md:gap-3.5 xl:rounded-card xl:border xl:border-line xl:bg-surface xl:p-5">
                <h2 id="m365" class="font-sans text-[0.8125rem] font-bold tracking-[0.06em] text-fg-muted uppercase">Microsoft 365</h2>
                <ul class="grid grid-cols-4 gap-2 md:gap-3 xl:grid-cols-2 xl:gap-2">
                    @foreach ($page->quickLinks as $link)
                        <li class="min-w-0">
                            <a href="{{ $link->url }}" target="_blank" rel="noopener" class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-[14px] border border-line bg-surface px-1 text-center text-xs font-semibold hover:border-line-strong md:min-h-14 md:flex-row md:justify-start md:gap-3 md:px-4 md:text-left md:text-[0.9375rem] xl:min-h-12 xl:gap-2.5 xl:rounded-control xl:border-transparent xl:bg-ground xl:px-2.5 xl:text-sm xl:hover:border-line">
                                <x-home.icon :name="$link->icon" stroke-width="1.75" />
                                <span class="max-w-full truncate">{{ $link->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>

    <nav aria-label="Navegación principal" class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden">
        <a href="{{ route('home') }}" aria-current="page" class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-bold text-brand-ink">
            <x-home.icon name="home" stroke-width="2" class="size-[22px]" />Inicio
        </a>
        <a href="#favoritos" class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-semibold text-fg-muted">
            <x-home.icon name="star" class="size-[22px]" />Favoritos
        </a>
        <a href="#buscar" data-focus-search class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-semibold text-fg-muted">
            <x-home.icon name="search" class="size-[22px]" />Buscar
        </a>
        <a href="#cuenta" data-open-account class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-semibold text-fg-muted">
            <x-home.icon name="user" class="size-[22px]" />Perfil
        </a>
    </nav>
</x-layouts.app>
