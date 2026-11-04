{{--
    Home screen (docs/diseno/Main, Tablet, PhoneHome, PhoneDark, PhoneSearch
    and PhoneEmpty) on the Liquid Glass photo, under the shared glass header
    (x-portal-header: «Cambiar de empresa», search, «Secciones» on phones and
    the account menu), with the `status` message of the last account action. The «Mi día»
    column (prototype of the new home, odd/tasks/inicio-fase-1.md WU3) holds the
    upcoming holidays, the announcements card (beside the apps only; phones and
    tablets get a banner with the top one), birthdays and work anniversaries of
    the week, the link to the company directory (/personas) and the Microsoft
    365 links. $page is an App\View\Home\HomePage: no queries here.
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
<x-layouts.app title="Inicio" viewport-fit glass assistant :data-server-search="$searching ? 'true' : 'false'" data-pwa-source="network">
    <x-slot:head>
        @vite('resources/js/home.js')
    </x-slot:head>

    {{-- docs/diseno/PhoneDark: shown by home.js without network or from the saved copy. --}}
    <p role="status" data-offline-notice hidden class="flex items-center gap-2 bg-status-maintenance-bg px-4 py-2.5 pt-[max(0.625rem,env(safe-area-inset-top))] text-[0.8125rem] font-semibold text-status-maintenance md:px-8 lg:px-10">
        <x-home.icon name="wifi-off" stroke-width="2" class="size-4" />
        <span data-offline-text>Sin conexión · mostrando tus apps guardadas</span>
    </p>

    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header :page="$page" />

    @if ($page->markets !== [])
        <x-home.markets :figures="$page->markets" />
    @endif

    {{-- g-sheet: a temporary glass surface over the photo until G6 restyles the blocks. --}}
    <div class="g-sheet mx-2 mt-3 mb-[calc(5.5rem+env(safe-area-inset-bottom))] flex max-w-[1440px] flex-col gap-10 px-4 pt-5 pb-6 md:mx-6 md:mt-6 md:mb-10 md:px-8 md:pt-8 md:pb-10 lg:flex-row lg:items-start lg:px-10 lg:pt-10 min-[1488px]:mx-auto">
        <main id="contenido" class="flex min-w-0 flex-1 flex-col gap-8 md:gap-10">
            {{-- The result of linking or switching accounts (App\Http\Controllers\Auth\MicrosoftLoginController).
                 «Completar»'s own message (work_status) goes in «Tareas pendientes», where its redirect lands (#tareas). --}}
            @php($completionStatus = is_string($flashed = session(\App\Http\Controllers\WorkItemCompletionController::FLASH_KEY)) && $flashed !== '' ? $flashed : null)
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

            @if ($page->management)
                <x-home.management-panel :card="$page->management" />
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

            @if ($page->news !== [])
                <x-home.news :tabs="$page->news" />
            @endif

            @if ($page->catalog)
                <x-home.catalog :shelf="$page->catalog" />
            @endif
        </main>

        {{-- «Mi día»: beside the apps on wide screens, after them on phones and tablets. --}}
        <aside id="mi-dia" aria-label="Mi día" class="flex min-w-0 flex-col divide-y divide-line rounded-card border border-line bg-surface lg:w-[340px] lg:shrink-0 xl:w-[360px]">
            @if ($page->work || $completionStatus)
                <x-home.work-list :work="$page->work" :status="$completionStatus" />
            @endif

            @if ($page->upcomingDates !== [])
                <section id="fechas" aria-labelledby="fechas-titulo" class="flex scroll-mt-6 flex-col gap-3.5 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="fechas-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Próximas fechas</h2>
                        @if ($page->hasEvents)
                            <a href="{{ route('events.index') }}" class="-my-3 inline-flex min-h-touch items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Ver eventos</a>
                        @endif
                    </div>
                    {{-- data-holiday-* and data-event-*: home.js corrects the saved copy replayed later. --}}
                    <ul class="flex flex-col gap-3">
                        @foreach ($page->upcomingDates as $date)
                            @if ($date instanceof \App\View\Home\EventDate)
                                <li class="flex items-center gap-3" data-event-date="{{ $date->isoDate() }}" data-event-end="{{ $date->overIso() }}">
                                    <time datetime="{{ $date->dayIsoDate() }}" aria-hidden="true" class="flex w-12 shrink-0 flex-col items-center rounded-[10px] bg-brand-tint py-1.5 leading-tight text-brand-ink">
                                        <span class="text-lg font-bold">{{ $date->dayNumber() }}</span>
                                        <span class="text-xs">{{ $date->shortMonth() }}</span>
                                    </time>
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        @if ($date->isOngoing)
                                            <span class="sr-only">{{ $date->ongoingDetail() }}, {{ mb_strtolower($date->kind()) }}:</span>
                                        @else
                                            <span class="sr-only">@if ($date->isToday)<span data-event-today>Hoy, </span>@endif{{ $date->dateAndTime() }}, {{ mb_strtolower($date->kind()) }}:</span>
                                        @endif
                                        <a href="{{ $date->href() }}" class="text-sm leading-snug font-bold break-words underline-offset-4 hover:underline">{{ $date->title }}</a>
                                        @if ($date->isOngoing)
                                            <p class="text-xs text-fg-muted" aria-hidden="true">{{ $date->ongoingDetail() }} · {{ $date->kind() }}</p>
                                        @else
                                            <p class="text-xs text-fg-muted" aria-hidden="true"><span data-event-weekday="{{ $date->dayName() }}">{{ $date->weekday() }}</span>@if ($detail = $date->shortDetail()) · {{ $detail }}@endif · {{ $date->kind() }}</p>
                                        @endif
                                    </div>
                                </li>
                                @continue
                            @endif
                            @php($holiday = $date)
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
                                    <p class="text-xs text-fg-muted" aria-hidden="true"><span data-holiday-weekday="{{ $holiday->dayName() }}">{{ $holiday->weekday() }}</span> · {{ $holiday->kind() }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Birthdays people chose to show and work anniversaries, today and the next seven days (App\View\Home\Celebration). --}}
            @if ($page->celebrations !== [])
                <section id="celebraciones" aria-labelledby="celebraciones-titulo" class="flex scroll-mt-6 flex-col gap-3.5 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="celebraciones-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Cumpleaños y aniversarios</h2>
                        @if ($page->moreCelebrationsHref)
                            <a href="{{ $page->moreCelebrationsHref }}" class="-my-3 inline-flex min-h-touch shrink-0 items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Ver directorio</a>
                        @endif
                    </div>
                    {{-- data-celebration-*: home.js corrects the saved copy replayed later. --}}
                    <ul class="flex flex-col gap-3">
                        @foreach ($page->celebrations as $celebration)
                            <li class="flex items-center gap-3" data-celebration-date="{{ $celebration->isoDate() }}">
                                <time datetime="{{ $celebration->isoDate() }}" aria-hidden="true" class="flex w-12 shrink-0 flex-col items-center rounded-[10px] bg-brand-tint py-1.5 leading-tight text-brand-ink">
                                    <span class="text-lg font-bold">{{ $celebration->dayNumber() }}</span>
                                    <span class="text-xs">{{ $celebration->shortMonth() }}</span>
                                </time>
                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <p class="text-sm leading-snug font-bold break-words" data-celebration-name>{{ $celebration->name }}</p>
                                    <p class="text-xs text-fg-muted"><span data-celebration-day="{{ $celebration->dayName() }}">{{ $celebration->dayLabel() }}</span><span class="sr-only">, {{ $celebration->longDate() }}</span> · <span data-celebration-kind>{{ $celebration->label }}</span></p>
                                </div>
                                @if ($celebration->greetHref)
                                    <a href="{{ $celebration->greetHref }}" target="_blank" rel="noopener" class="-my-2 inline-flex min-h-touch shrink-0 items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Felicitar<span class="sr-only"> a {{ $celebration->name }} por Teams</span></a>
                                @endif
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

            @if ($page->peopleHref)
                <section id="personas" aria-labelledby="personas-titulo" class="flex scroll-mt-6 flex-col gap-2 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="personas-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Personas</h2>
                        <a href="{{ $page->peopleHref }}" class="-my-3 inline-flex min-h-touch items-center text-[0.8125rem] font-semibold text-brand-ink underline-offset-4 hover:underline">Ver directorio</a>
                    </div>
                    <p class="text-sm text-fg-muted">Encuentra a tus compañeros por nombre, cargo o área.</p>
                </section>
            @endif

            @if ($page->itStatus)
                <x-home.it-status :status="$page->itStatus" />
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
