{{--
    Home screen (docs/diseno/Main, Tablet, PhoneHome, PhoneDark, PhoneSearch
    and PhoneEmpty), with «Cambiar de empresa» in the header for linked
    accounts and the `status` message of the last account action. The «Mi día»
    column (prototype of the new home, odd/tasks/inicio-fase-1.md WU3) holds the
    upcoming holidays, the announcements card (beside the apps only; phones and
    tablets get a banner with the top one) and the Microsoft 365 links. Phones
    also get a «Secciones» menu. $page is an App\View\Home\HomePage: no queries here.
    Works without JavaScript; resources/js/home.js adds instant search, the
    «Ctrl K» search dialog (x-home.search-dialog, not on a server search), the
    favorite toggle and the offline notice. No Livewire or Alpine on this page.
    data-pwa-source turns to "cache" when the service worker (resources/js/sw.js)
    answers with the saved copy of this page.
--}}
@php
    /** @var \App\View\Home\HomePage $page */
    $searching = $page->isSearching();
@endphp
<x-layouts.app title="Inicio" viewport-fit brand-band :data-server-search="$searching ? 'true' : 'false'" data-pwa-source="network">
    <x-slot:head>
        @vite('resources/js/home.js')
    </x-slot:head>

    {{-- docs/diseno/PhoneDark: shown by home.js without network or from the saved copy. --}}
    <p role="status" data-offline-notice hidden class="flex items-center gap-2 bg-status-maintenance-bg px-4 py-2.5 pt-[max(0.625rem,env(safe-area-inset-top))] text-[0.8125rem] font-semibold text-status-maintenance md:px-8 lg:px-10">
        <x-home.icon name="wifi-off" stroke-width="2" class="size-4" />
        <span data-offline-text>Sin conexión · mostrando tus apps guardadas</span>
    </p>

    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <header class="brand-band">
        <div class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-x-3 gap-y-3 px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-4 md:h-[72px] md:flex-nowrap md:gap-x-8 md:px-8 md:py-0 lg:px-10">
            <a href="{{ route('home') }}" class="order-1 flex min-h-touch min-w-0 flex-1 items-center gap-3 md:flex-none md:shrink-0 xl:w-[300px]">
                <x-brand-logo on-band>
                    <span class="hidden text-xs text-white/80 md:block">Portal de aplicaciones</span>
                </x-brand-logo>
            </a>

            {{-- Its own row on phones, between the search and the account on wider screens. --}}
            @if ($page->switcher)
                <x-account-switcher :switcher="$page->switcher" class="order-3 min-w-0 basis-full md:order-3 md:basis-auto md:shrink-0" />
            @endif

            {{-- Phones: jump to a section of this long page; a <details>, so it works without JavaScript. --}}
            @if ($sections = $page->sections())
                <details class="relative order-4 md:hidden" data-sections>
                    <summary class="flex h-12 cursor-pointer list-none items-center gap-2 rounded-[14px] border border-white/40 px-3 text-sm font-semibold hover:bg-white/10 [&::-webkit-details-marker]:hidden">
                        <x-home.icon name="menu" stroke-width="2" class="size-[18px]" />Secciones
                    </summary>
                    <nav aria-label="Secciones de la página" class="absolute top-full left-0 z-40 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-card border border-line bg-surface p-2 text-fg shadow-card [&_:focus-visible]:outline-fg">
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($sections as $section)
                                <li><a href="{{ $section['href'] }}" class="flex min-h-touch items-center rounded-control px-3 text-sm font-semibold hover:bg-subtle">{{ $section['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                </details>
            @endif

            @if ($page->hasApps())
                <form role="search" method="GET" action="{{ route('home') }}" class="order-4 min-w-0 flex-1 basis-40 md:order-2 md:mx-auto md:max-w-[560px] md:basis-auto" data-search-form>
                    <label for="buscar" class="sr-only">Buscar aplicaciones</label>
                    <div class="flex h-12 items-center gap-2.5 rounded-[14px] bg-surface pr-2 pl-3.5 text-fg focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-white md:h-11 md:rounded-control">
                        <x-home.icon name="search" stroke-width="2" class="size-[18px] text-fg-muted" />
                        <input id="buscar" name="q" type="search" value="{{ $page->query }}" maxlength="{{ \App\Catalog\AppSearch::MAX_LENGTH }}" placeholder="Buscar aplicaciones" autocomplete="off" enterkeyhint="search" aria-describedby="resultados" class="h-full min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-fg-muted md:text-[0.9375rem]">
                        @if ($page->searchIndex)
                            {{-- home.js shows it: the «Ctrl K» search over apps, announcements and dates. --}}
                            <button type="button" data-search-open hidden class="hidden min-h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-md border border-line-strong px-2 text-xs font-semibold text-fg-muted hover:bg-subtle md:inline-flex">
                                <span class="sr-only">Buscar en todo el portal</span><kbd class="font-sans" data-search-shortcut>Ctrl K</kbd>
                            </button>
                        @else
                            {{-- A server search (?q=) has no dialog: «/» focuses this field (home.js). --}}
                            <kbd class="hidden rounded-md border border-line-strong bg-surface px-2 py-0.5 font-sans text-xs text-fg-muted md:inline" title="Pulsa / para buscar">/</kbd>
                        @endif
                    </div>
                </form>
            @endif

            <div class="order-2 ml-auto flex items-center gap-3 md:order-3 md:shrink-0 xl:w-[300px] xl:justify-end">
                @if ($page->supportHref)
                    {{-- Icon only on phones (docs/diseno/PhoneHome), labelled from tablets up (Main, Tablet). --}}
                    <a href="{{ $page->supportHref }}" target="_blank" rel="noopener" class="inline-flex size-touch items-center justify-center gap-2 rounded-control border border-white/40 text-sm font-semibold hover:bg-white/10 md:w-auto md:px-4">
                        <x-home.icon name="lifebuoy" class="size-5 md:size-[18px]" />
                        <span class="sr-only md:not-sr-only">Soporte TI</span>
                    </a>
                @endif
                <details id="cuenta" class="relative" data-account>
                    <summary class="flex size-touch cursor-pointer list-none items-center justify-center rounded-full bg-brand-tint text-[0.9375rem] font-bold text-brand-ink [&::-webkit-details-marker]:hidden">
                        <span aria-hidden="true">{{ $page->userInitials }}</span>
                        <span class="sr-only">Cuenta de {{ $page->userName }}</span>
                    </summary>
                    <div class="absolute top-full right-0 z-40 mt-2 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-card border border-line bg-surface p-2 text-fg shadow-card [&_:focus-visible]:outline-fg">
                        <div class="flex flex-col px-3 py-2">
                            <span class="font-semibold">{{ $page->userName }}</span>
                            @if ($page->userEmail)
                                <span class="truncate text-[0.8125rem] text-fg-muted">{{ $page->userEmail }}</span>
                            @endif
                        </div>
                        @if ($page->accountsHref)
                            <a href="{{ $page->accountsHref }}" class="flex min-h-touch items-center gap-3 rounded-control px-3 text-sm font-semibold hover:bg-subtle">
                                <x-home.icon name="link" class="size-[18px] text-fg-muted" />Cuentas vinculadas
                            </a>
                        @endif
                        @if ($page->canAdminister)
                            <a href="{{ route('admin.index') }}" class="flex min-h-touch items-center gap-3 rounded-control px-3 text-sm font-semibold hover:bg-subtle">
                                <x-home.icon name="settings" class="size-[18px] text-fg-muted" />Administración
                            </a>
                        @endif
                        <form method="POST" action="{{ route('logout') }}" data-logout>
                            @csrf
                            <button type="submit" class="flex min-h-touch w-full cursor-pointer items-center gap-3 rounded-control px-3 text-left text-sm font-semibold hover:bg-subtle">
                                <x-home.icon name="logout" class="size-[18px] text-fg-muted" />Cerrar sesión
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <div class="mx-auto flex max-w-[1440px] flex-col gap-10 px-4 pt-2 pb-[calc(6rem+env(safe-area-inset-bottom))] md:px-8 md:pt-8 md:pb-10 lg:flex-row lg:items-start lg:px-10 lg:pt-10">
        <main id="contenido" class="flex min-w-0 flex-1 flex-col gap-8 md:gap-10">
            {{-- The result of linking or switching accounts (App\Http\Controllers\Auth\MicrosoftLoginController). --}}
            @if (is_string($status = session('status')) && $status !== '')
                <p role="status" data-flash class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold">{{ $status }}</p>
            @endif

            <div class="flex flex-col gap-1 md:gap-1.5">
                <p class="flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                    <span class="font-semibold text-brand-ink">{{ $page->dateLine }}</span>
                    {{-- data-holiday-date: home.js hides it in the saved copy once that day is over. --}}
                    @if ($holidayNotice = $page->holidayNotice())
                        <span class="text-fg-muted" data-holiday-notice data-holiday-date="{{ $page->nextHoliday->isoDate() }}">{{ $holidayNotice }}</span>
                    @endif
                </p>
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
                <x-announcements.banner :card="$top" data-announcement-banner class="lg:hidden" />
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
                {{-- Page order of the cards, for the logo entrance (app-card). It moves on
                     in its own statement: Blade evaluates component attributes twice. --}}
                @php($order = 0)

                <div class="flex flex-col gap-8 xl:flex-row xl:items-start xl:gap-10">
                    <section id="favoritos" aria-labelledby="favoritos-titulo" data-section class="flex min-w-0 scroll-mt-6 flex-col gap-3 md:gap-3.5 xl:shrink" @if ($searching && $page->favorites === []) hidden @endif>
                        <h2 id="favoritos-titulo" class="font-sans text-sm font-bold tracking-normal text-brand-text">Favoritos</h2>
                        <p data-favorites-hint class="max-w-xs text-sm text-fg-muted" @if ($page->favorites !== []) hidden @endif>Toca la estrella de una aplicación para fijarla aquí.</p>
                        <ul data-list class="grid grid-cols-2 gap-3 md:flex md:flex-wrap md:gap-4">
                            @foreach ($page->favorites as $card)
                                <x-home.app-card :card="$card" :query="$page->query" :order="$order" />
                                @php($order++)
                            @endforeach
                        </ul>
                    </section>

                    @if ($page->recents !== [])
                        <section id="recientes" aria-labelledby="recientes-titulo" class="flex min-w-0 scroll-mt-6 flex-col gap-3 md:gap-3.5 xl:min-w-[280px] xl:flex-1">
                            <h2 id="recientes-titulo" class="font-sans text-sm font-bold tracking-normal text-brand-text">Recientes</h2>
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

                <div id="aplicaciones" class="flex scroll-mt-6 flex-col gap-8 md:flex-row md:flex-wrap md:gap-x-10 md:gap-y-7">
                    @foreach ($page->categories as $group)
                        <section aria-labelledby="categoria-{{ $group->slug }}" data-section data-category="{{ $group->slug }}" class="flex min-w-0 max-w-full flex-col gap-3 md:gap-3.5" @if ($group->isEmpty()) hidden @endif>
                            <h2 id="categoria-{{ $group->slug }}" class="font-sans text-sm font-bold tracking-normal text-brand-text">{{ $group->name }}</h2>
                            <ul data-list class="grid grid-cols-2 gap-3 md:flex md:flex-wrap md:gap-4">
                                @foreach ($group->cards as $card)
                                    <x-home.app-card :card="$card" :query="$page->query" :order="$order" />
                                    @php($order++)
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            @endif
        </main>

        {{-- «Mi día»: beside the apps on wide screens, after them on phones and tablets. --}}
        <aside id="mi-dia" aria-label="Mi día" class="flex min-w-0 flex-col divide-y divide-line rounded-card border border-line bg-surface lg:w-[340px] lg:shrink-0 xl:w-[360px]">
            @if ($page->upcomingHolidays !== [])
                <section id="fechas" aria-labelledby="fechas-titulo" class="flex scroll-mt-6 flex-col gap-3.5 p-5">
                    <h2 id="fechas-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Próximas fechas</h2>
                    {{-- data-holiday-*: home.js corrects the saved copy replayed on a later day. --}}
                    <ul class="flex flex-col gap-3">
                        @foreach ($page->upcomingHolidays as $holiday)
                            <li class="flex items-center gap-3" data-holiday-date="{{ $holiday->isoDate() }}">
                                <time datetime="{{ $holiday->isoDate() }}" aria-hidden="true" class="flex w-12 shrink-0 flex-col items-center rounded-[10px] bg-brand-tint py-1.5 leading-tight text-brand-ink">
                                    <span class="text-lg font-bold">{{ $holiday->dayNumber() }}</span>
                                    <span class="text-xs">{{ $holiday->shortMonth() }}</span>
                                </time>
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="sr-only">@if ($holiday->isToday)<span data-holiday-today>Hoy, </span>@endif{{ $holiday->longDate() }}:</span>
                                    @foreach ($holiday->names as $name)
                                        <p class="text-sm leading-snug font-bold break-words">{{ $name }}</p>
                                    @endforeach
                                    <p class="text-xs text-fg-muted" aria-hidden="true"><span data-holiday-weekday="{{ $holiday->dayName() }}">{{ $holiday->weekday() }}</span> · Festivo</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Beside the apps only; phones and tablets get the banner above. --}}
            @if ($page->announcements !== [])
                <section aria-labelledby="anuncios" data-announcements class="hidden flex-col gap-4 p-5 lg:flex">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="anuncios" class="font-sans text-base font-bold tracking-normal text-brand-text">Anuncios</h2>
                        <a href="{{ route('announcements.index') }}" class="-my-3 inline-flex min-h-touch items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Ver todos<span class="sr-only"> los anuncios</span></a>
                    </div>
                    <ul class="flex flex-col divide-y divide-line">
                        @foreach ($page->announcements as $announcement)
                            <li class="py-4 first:pt-0 last:pb-0">
                                <x-announcements.card :card="$announcement" from="home" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($page->quickLinks !== [])
                <section aria-labelledby="m365" class="flex flex-col gap-3.5 p-5">
                    <h2 id="m365" class="scroll-mt-6 font-sans text-base font-bold tracking-normal text-brand-text">Microsoft 365</h2>
                    <ul class="grid grid-cols-4 gap-2 md:gap-3 lg:grid-cols-2 lg:gap-2">
                        @foreach ($page->quickLinks as $link)
                            <li class="min-w-0">
                                <a href="{{ $link->url }}" target="_blank" rel="noopener" class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-[14px] border border-line bg-surface px-1 text-center text-xs font-semibold hover:border-line-strong md:min-h-14 md:flex-row md:justify-start md:gap-3 md:px-4 md:text-left md:text-[0.9375rem] lg:min-h-12 lg:gap-2.5 lg:rounded-control lg:border-transparent lg:bg-ground lg:px-2.5 lg:text-sm lg:hover:border-line">
                                    <x-home.icon :name="$link->icon" stroke-width="1.75" />
                                    <span class="max-w-full truncate">{{ $link->label }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
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

    {{-- Not on a server search (?q=): that page is the plain search without JavaScript. --}}
    @if ($page->searchIndex)
        <x-home.search-dialog :index="$page->searchIndex" />
    @endif
</x-layouts.app>
