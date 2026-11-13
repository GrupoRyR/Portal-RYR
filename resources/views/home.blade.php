{{--
    Home screen in Liquid Glass (odd/tasks/liquid-glass.md G6) under the
    shared glass header (x-portal-header) and the «Mercados» band, with the
    block placements of the approved prototype:

    - the main column (<main>): the greeting banner, then the glass cards
      «Panel gerencial» (only «Gerencia»), «Mis aplicaciones» (favorites,
      recents and the categories, or one list from A to Z in the view the
      account picks in its head), «Accesos rápidos», «Anuncios»,
      «Noticias» and «Catálogos y categorías»;
    - «Mi día» (<aside>, beside it from 1024 px, after it on phones and
      tablets): «Tareas pendientes», the mandatory announcements still to
      confirm, «Cumpleaños y aniversarios», «Próximas fechas», «Personas»
      and «Estado de TI».

    The glass styles live in resources/css/glass.css, section 7. $page is an
    App\View\Home\HomePage: no queries here.
    Works without JavaScript; resources/js/home.js adds instant search, the
    «Ctrl K» search dialog (x-home.search-dialog, not on a server search), the
    favorite toggle and the offline notice. No Livewire or Alpine on this page.
    data-pwa-source turns to "cache" when the service worker (resources/js/sw.js)
    answers with the saved copy of this page.
--}}
@use('App\View\Home\AppsView')
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

    <div class="g-home">
        <main id="contenido" class="g-home__col">
            <section id="inicio" aria-labelledby="inicio-titulo" class="g-banner g-hello">
                <div class="min-w-0">
                    <h1 id="inicio-titulo">{{ $page->greeting() }}</h1>
                    <p class="g-hello__sub">
                        <span class="font-semibold text-(--g-ink)">{{ $page->dateLine }}</span>
                        {{-- data-holiday-date: home.js hides it in the saved copy once that day is over. --}}
                        @if ($holidayNotice = $page->holidayNotice())
                            <span data-holiday-notice data-holiday-date="{{ $page->nextHoliday->isoDate() }}">{{ $holidayNotice }}</span>
                        @endif
                    </p>
                </div>
            </section>

            {{-- The result of linking or switching accounts (App\Http\Controllers\Auth\MicrosoftLoginController).
                 «Completar»'s own message (work_status) goes in «Tareas pendientes», where its redirect lands (#tareas). --}}
            @php($completionStatus = is_string($flashed = session(\App\Http\Controllers\WorkItemCompletionController::FLASH_KEY)) && $flashed !== '' ? $flashed : null)
            @if (is_string($status = session('status')) && $status !== '')
                <p role="status" data-flash class="g-card g-flash">{{ $status }}</p>
            @endif

            @if ($page->management)
                <x-home.management-panel :card="$page->management" />
            @endif

            <section id="mis-aplicaciones" aria-labelledby="mis-aplicaciones-titulo" class="g-card scroll-mt-6">
                <div class="g-card__head">
                    <span class="g-mark" aria-hidden="true"></span>
                    <h2 id="mis-aplicaciones-titulo">Mis aplicaciones</h2>
                    @if ($page->hasApps())
                        <span class="g-note">{{ $page->availability() }}</span>

                        {{-- The view of the apps (App\View\Home\AppsView): a plain form, so it
                             works without JavaScript (AppsViewController), back here keeping the search. --}}
                        <form method="POST" action="{{ route('home.apps-view') }}" class="g-apps-view">
                            @csrf
                            @method('PUT')
                            @if ($page->query !== '')
                                <input type="hidden" name="q" value="{{ $page->query }}">
                            @endif
                            <div class="g-seg" role="group" aria-label="Vista de aplicaciones">
                                @foreach (AppsView::cases() as $appsView)
                                    <button type="submit" name="view" value="{{ $appsView->value }}" aria-pressed="{{ $appsView === $page->appsView ? 'true' : 'false' }}">{{ $appsView->label() }}</button>
                                @endforeach
                            </div>
                        </form>
                    @endif
                </div>

                <div class="g-card__body">
                    @if (! $page->hasApps())
                        <div class="flex flex-col items-center gap-3 py-2 text-center md:items-start md:text-left">
                            <span class="flex size-16 items-center justify-center rounded-full bg-brand-tint text-brand-ink">
                                <x-home.icon name="apps-add" stroke-width="1.6" class="size-8" />
                            </span>
                            <h3 id="sin-apps" class="text-lg leading-snug font-bold tracking-normal">Aún no tienes aplicaciones asignadas</h3>
                            <p class="text-[0.9375rem] leading-normal text-fg-soft">Solicítalas a TI. Cuando te den acceso, aparecerán aquí.</p>
                        </div>
                    @else
                        <p id="resultados" aria-live="polite" class="mb-3 text-sm font-semibold empty:mb-0">@if ($searching){{ $page->resultSummary() }}@endif</p>
                        @if ($searching)
                            <a href="{{ route('home') }}" class="-mt-2 mb-2 inline-flex min-h-touch items-center text-sm font-semibold text-brand-ink underline-offset-4 hover:underline">Ver todas las aplicaciones</a>
                        @endif

                        {{-- Page order of the cards, for the logo entrance (app-card). It moves on
                             in its own statement: Blade evaluates component attributes twice. --}}
                        @php($order = 0)

                        @if ($page->appsView->isFlat())
                        {{-- «Todas», «Lista» and «Íconos»: every app once, from A to Z, the
                             favorites in place with their star on (HomePageBuilder). --}}
                        <ul id="aplicaciones" data-list @class([
                            'g-apps',
                            'g-apps--list' => $page->appsView === AppsView::List,
                            'g-apps--launcher' => $page->appsView === AppsView::Launcher,
                            'scroll-mt-6',
                        ])>
                            @foreach ($page->apps as $card)
                                <x-home.app-card :card="$card" :query="$page->query" :order="$order" :view="$page->appsView" />
                                @php($order++)
                            @endforeach
                        </ul>
                        @else
                        <div class="flex flex-col gap-6">
                            <section id="favoritos" aria-labelledby="favoritos-titulo" data-section class="g-apps-group min-w-0 scroll-mt-6" @if ($searching && $page->favorites === []) hidden @endif>
                                <h3 id="favoritos-titulo">Favoritos</h3>
                                <p data-favorites-hint class="text-sm text-fg-muted" @if ($page->favorites !== []) hidden @endif>Toca la estrella de una aplicación para fijarla aquí.</p>
                                <ul data-list class="g-apps">
                                    @foreach ($page->favorites as $card)
                                        <x-home.app-card :card="$card" :query="$page->query" :order="$order" />
                                        @php($order++)
                                    @endforeach
                                </ul>
                            </section>

                            @if ($page->recents !== [])
                                <section id="recientes" aria-labelledby="recientes-titulo" class="g-apps-group min-w-0 scroll-mt-6">
                                    <h3 id="recientes-titulo">Recientes</h3>
                                    <ul class="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($page->recents as $card)
                                            <li class="min-w-0">
                                                <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="flex min-h-touch items-center gap-3 rounded-control px-2 py-1.5 hover:bg-subtle">
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

                            <div id="aplicaciones" class="flex scroll-mt-6 flex-col gap-6">
                                @foreach ($page->categories as $group)
                                    <section aria-labelledby="categoria-{{ $group->slug }}" data-section data-category="{{ $group->slug }}" class="g-apps-group min-w-0" @if ($group->isEmpty()) hidden @endif>
                                        <h3 id="categoria-{{ $group->slug }}">{{ $group->name }}</h3>
                                        <ul data-list class="g-apps">
                                            @foreach ($group->cards as $card)
                                                <x-home.app-card :card="$card" :query="$page->query" :order="$order" />
                                                @php($order++)
                                            @endforeach
                                        </ul>
                                    </section>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    @endif
                </div>
            </section>

            {{-- The company's shortcuts (App\View\Home\QuickLinks): Outlook, Teams and OneDrive, then its own. --}}
            @if ($page->quickLinks !== [])
                <section id="accesos" aria-labelledby="accesos-titulo" class="g-card scroll-mt-6">
                    <div class="g-card__head">
                        <span class="g-mark" aria-hidden="true"></span>
                        <h2 id="accesos-titulo">Accesos rápidos</h2>
                    </div>
                    <div class="g-card__body">
                        <ul class="g-quick">
                            @foreach ($page->quickLinks as $link)
                                <li class="min-w-0">
                                    <a href="{{ $link->url }}" target="_blank" rel="noopener" class="g-chip">
                                        <x-home.icon :name="$link->icon" stroke-width="1.75" />
                                        <span class="min-w-0 truncate">{{ $link->label }}</span><span class="sr-only"> (se abre en otra pestaña)</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif

            {{-- AnnouncementReadController brings the person back to #anuncios after «Confirmar lectura». --}}
            @if ($page->announcements !== [])
                <section id="anuncios" aria-labelledby="anuncios-titulo" data-announcements class="g-card scroll-mt-6">
                    <div class="g-card__head">
                        <span class="g-mark" aria-hidden="true"></span>
                        <h2 id="anuncios-titulo">Anuncios</h2>
                        <a href="{{ route('announcements.index') }}" class="g-more">Ver todos<span class="sr-only"> los anuncios</span></a>
                    </div>
                    <div class="g-card__body">
                        <ul class="g-anns">
                            @foreach ($page->announcements as $announcement)
                                <li @class(['g-tile g-ann min-w-0', 'g-ann--must' => $announcement->pendingRead()])>
                                    <x-announcements.card :card="$announcement" from="home" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif

            @if ($page->news !== [])
                <x-home.news :tabs="$page->news" />
            @endif

            @if ($page->catalog)
                <x-home.catalog :shelf="$page->catalog" />
            @endif
        </main>

        {{-- «Mi día»: beside the main column from 1024 px, after it on phones and tablets. --}}
        <aside id="mi-dia" aria-labelledby="mi-dia-titulo" class="g-card g-rail min-w-0 scroll-mt-6">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="mi-dia-titulo">Mi día</h2>
            </div>

            @if ($page->work || $completionStatus)
                <x-home.work-list :work="$page->work" :status="$completionStatus" />
            @endif

            {{-- The mandatory announcements not confirmed yet, as the bell lists them (no query of its own). --}}
            @if (($toConfirm = $page->toConfirm()) !== [])
                <section id="por-confirmar" aria-labelledby="por-confirmar-titulo" class="flex scroll-mt-6 flex-col gap-3">
                    <h3 id="por-confirmar-titulo">Por confirmar</h3>
                    <ul class="flex flex-col gap-3">
                        @foreach ($toConfirm as $notice)
                            <li class="flex min-w-0 flex-col gap-0.5">
                                <a href="{{ $notice->href }}" class="text-sm leading-snug font-bold break-words underline-offset-4 hover:underline">{{ $notice->title }}</a>
                                <p class="text-xs text-fg-muted">{{ $notice->detail }}</p>
                            </li>
                        @endforeach
                    </ul>
                    @if ($page->moreToConfirm())
                        <a href="{{ route('announcements.index') }}" class="g-more my-0 ml-0 self-start">Ver todos<span class="sr-only"> los anuncios</span></a>
                    @endif
                </section>
            @endif

            {{-- Birthdays people chose to show and work anniversaries, today and the next seven days (App\View\Home\Celebration). --}}
            @if ($page->celebrations !== [])
                <section id="celebraciones" aria-labelledby="celebraciones-titulo" class="flex scroll-mt-6 flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <h3 id="celebraciones-titulo">Cumpleaños y aniversarios</h3>
                        @if ($page->moreCelebrationsHref)
                            <a href="{{ $page->moreCelebrationsHref }}" class="g-more shrink-0">Ver directorio</a>
                        @endif
                    </div>
                    {{-- data-celebration-*: home.js corrects the saved copy replayed later. --}}
                    <ul class="flex flex-col gap-3">
                        @foreach ($page->celebrations as $celebration)
                            <li class="flex items-center gap-3" data-celebration-date="{{ $celebration->isoDate() }}">
                                <time datetime="{{ $celebration->isoDate() }}" aria-hidden="true" class="g-day">
                                    <span class="text-[1.0625rem] font-bold">{{ $celebration->dayNumber() }}</span>
                                    <span class="text-[0.6875rem] text-fg-muted uppercase">{{ $celebration->shortMonth() }}</span>
                                </time>
                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <p class="text-sm leading-snug font-bold break-words" data-celebration-name>{{ $celebration->name }}</p>
                                    <p class="text-xs text-fg-muted"><span data-celebration-day="{{ $celebration->dayName() }}">{{ $celebration->dayLabel() }}</span><span class="sr-only">, {{ $celebration->longDate() }}</span> · <span data-celebration-kind>{{ $celebration->label }}</span></p>
                                </div>
                                @if ($celebration->greetHref)
                                    <a href="{{ $celebration->greetHref }}" target="_blank" rel="noopener" class="g-btn shrink-0 px-3 text-[0.8125rem]">Felicitar<span class="sr-only"> a {{ $celebration->name }} por Teams</span></a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($page->upcomingDates !== [])
                <section id="fechas" aria-labelledby="fechas-titulo" class="flex scroll-mt-6 flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <h3 id="fechas-titulo">Próximas fechas</h3>
                        @if ($page->hasEvents)
                            <a href="{{ route('events.index') }}" class="g-more">Ver eventos</a>
                        @endif
                    </div>
                    {{-- data-holiday-* and data-event-*: home.js corrects the saved copy replayed later. --}}
                    <ul class="flex flex-col gap-3">
                        @foreach ($page->upcomingDates as $date)
                            @if ($date instanceof \App\View\Home\EventDate)
                                <li class="flex items-center gap-3" data-event-date="{{ $date->isoDate() }}" data-event-end="{{ $date->overIso() }}">
                                    <time datetime="{{ $date->dayIsoDate() }}" aria-hidden="true" class="g-day">
                                        <span class="text-[1.0625rem] font-bold">{{ $date->dayNumber() }}</span>
                                        <span class="text-[0.6875rem] text-fg-muted uppercase">{{ $date->shortMonth() }}</span>
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
                                <time datetime="{{ $holiday->isoDate() }}" aria-hidden="true" class="g-day g-day--accent">
                                    <span class="text-[1.0625rem] font-bold">{{ $holiday->dayNumber() }}</span>
                                    <span class="text-[0.6875rem] uppercase">{{ $holiday->shortMonth() }}</span>
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

            {{-- The company directory: a plain GET form to /personas, which searches by name, position or area. --}}
            @if ($page->peopleHref)
                <section id="personas" aria-labelledby="personas-titulo" class="flex scroll-mt-6 flex-col gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <h3 id="personas-titulo">Personas</h3>
                        <a href="{{ $page->peopleHref }}" class="g-more">Ver directorio</a>
                    </div>
                    <form method="GET" action="{{ $page->peopleHref }}" role="search" aria-label="Buscar en el directorio" class="flex gap-2">
                        <label for="directorio-q" class="sr-only">Nombre, cargo o área</label>
                        <input id="directorio-q" name="q" type="search" maxlength="{{ \App\Catalog\AppSearch::MAX_LENGTH }}" autocomplete="off" enterkeyhint="search" placeholder="Nombre, cargo o área" class="g-input min-w-0 flex-1 text-base font-medium">
                        <button type="submit" class="g-btn shrink-0 px-3" aria-label="Buscar en el directorio">
                            <x-home.icon name="search" stroke-width="2" />
                        </button>
                    </form>
                </section>
            @endif

            @if ($page->itStatus)
                <x-home.it-status :status="$page->itStatus" />
            @endif

            {{-- Nothing above (an emergency account, say): the card still answers /#mi-dia. --}}
            @if (! ($page->work || $completionStatus) && $toConfirm === [] && $page->celebrations === [] && $page->upcomingDates === [] && ! $page->peopleHref && ! $page->itStatus)
                <p class="g-rail__empty">Nada pendiente por hoy.</p>
            @endif
        </aside>
    </div>

    <nav aria-label="Navegación principal" class="g-dock fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 pb-[env(safe-area-inset-bottom)] md:hidden">
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
