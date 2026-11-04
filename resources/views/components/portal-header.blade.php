{{--
    Glass top bar of the portal (App\View\Components\PortalHeader), approved
    sketch of 2026-11-04: the logo on its plate (the white Segurex logo on
    --brand-dark, SDC on white), «Portal RYR», «Cambiar de empresa», the
    search, «Soporte TI», the bell slot (G4) and the user menu. Phones get a
    second row with «Secciones» (home screen) and the search.

    Works without JavaScript: menus are <details>, the search is a GET form
    to the home screen. resources/js/header.js closes the menus and shows the
    theme choice; resources/js/home.js adds «Ctrl K» on the home screen.
    The ids (#buscar, #cuenta, #resultados) and data-* hooks are home.js's.
--}}
@php($row = 'flex min-h-touch items-center gap-3 rounded-[14px] px-3 text-sm font-semibold hover:bg-(--g-hover-row)')
@php($icon = 'size-[18px] shrink-0 text-(--g-ink-faint)')
<header {{ $attributes->class(['g-topbar']) }} data-portal-header>
    <div class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-2 px-3 py-2 md:flex-nowrap md:gap-3 md:px-8 lg:px-10">
        <a href="{{ route('home') }}" aria-label="Ir al inicio" class="order-1 flex min-h-touch min-w-0 shrink-0 items-center gap-3 rounded-control">
            <x-brand-logo />
            <span class="hidden flex-col border-l border-(--g-line-strong) pl-3 text-xs leading-tight text-(--g-ink-soft) xl:flex">
                <span class="text-sm font-bold text-(--g-ink)">Portal RYR</span>
                <span aria-hidden="true">{{ $companyName }}</span>
            </span>
        </a>

        {{-- Phones change company from «Tus empresas» in the user menu. --}}
        @if ($switcher)
            <x-account-switcher :switcher="$switcher" class="order-2 hidden min-w-0 shrink-0 sm:block" />
        @endif

        {{-- A second row on phones; its items join the bar from tablets up. --}}
        <div class="order-4 flex min-w-0 basis-full items-center gap-2 md:contents">
            @if ($sections !== [])
                <details class="relative md:hidden" data-sections data-menu>
                    <summary class="g-btn list-none [&::-webkit-details-marker]:hidden">
                        <x-home.icon name="menu" stroke-width="2" class="size-[18px]" />Secciones
                    </summary>
                    <nav aria-label="Secciones de la página" class="g-pop left-0 w-64">
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($sections as $section)
                                <li @if (($section['offline'] ?? true) === false) data-offline-omit @endif><a href="{{ $section['href'] }}" class="{{ $row }}">{{ $section['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </nav>
                </details>
            @endif

            @if ($hasSearch)
                <form role="search" aria-label="Buscar en el portal" method="GET" action="{{ route('home') }}" class="min-w-0 flex-1 md:order-3 md:mx-auto md:max-w-[520px]" data-search-form>
                    <label for="buscar" class="sr-only">Buscar aplicaciones</label>
                    <div class="g-input flex h-11 items-center gap-2.5 pr-2 pl-3.5 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--g-ink)">
                        <x-home.icon name="search" stroke-width="2" class="size-[18px] shrink-0 text-(--g-ink-faint)" />
                        <input id="buscar" name="q" type="search" value="{{ $query }}" maxlength="{{ \App\Catalog\AppSearch::MAX_LENGTH }}" placeholder="Buscar aplicaciones" autocomplete="off" enterkeyhint="search" @if ($page) aria-describedby="resultados" @endif class="h-full min-w-0 flex-1 bg-transparent text-base font-medium outline-none placeholder:text-(--g-ink-faint) md:text-[0.9375rem]">
                        @if ($hasSearchDialog)
                            {{-- home.js shows it: the «Ctrl K» search over apps, announcements, dates and people. --}}
                            <button type="button" data-search-open hidden class="hidden min-h-8 shrink-0 cursor-pointer items-center rounded-md border border-(--g-line-strong) px-2 text-xs font-semibold text-(--g-ink-soft) hover:bg-(--g-hover-row) md:inline-flex">
                                <span class="sr-only">Buscar en todo el portal</span><kbd class="font-sans" data-search-shortcut>Ctrl K</kbd>
                            </button>
                        @elseif ($page)
                            {{-- A server search (?q=) has no dialog: «/» focuses this field (home.js). --}}
                            <kbd class="hidden rounded-md border border-(--g-line-strong) px-2 py-0.5 font-sans text-xs text-(--g-ink-soft) md:inline" title="Pulsa / para buscar">/</kbd>
                        @endif
                    </div>
                </form>
            @endif
        </div>

        <div class="order-3 ml-auto flex shrink-0 items-center gap-2 md:order-4">
            @if ($supportHref)
                {{-- Icon only on phones, labelled from tablets up. --}}
                <a href="{{ $supportHref }}" target="_blank" rel="noopener" class="g-btn size-touch p-0 md:w-auto md:px-4">
                    <x-home.icon name="lifebuoy" class="size-5 md:size-[18px]" />
                    <span class="sr-only md:not-sr-only">Soporte TI</span>
                </a>
            @endif

            {{-- G4: the bell goes here. --}}
            @isset($bell)
                <div class="flex items-center" data-header-bell>
                    {{ $bell }}
                </div>
            @endisset

            <details id="cuenta" class="relative" data-account data-menu>
                <summary class="g-btn list-none gap-2 p-1 lg:pr-3.5 [&::-webkit-details-marker]:hidden">
                    <span aria-hidden="true" class="grid size-9 place-items-center rounded-full bg-(--g-accent-tint) text-[0.8125rem] font-bold text-(--g-accent-ink)">{{ $userInitials }}</span>
                    <span aria-hidden="true" class="hidden max-w-40 truncate text-[0.8125rem] lg:block">{{ $userName }}</span>
                    <span class="sr-only">Cuenta de {{ $userName }}</span>
                </summary>
                <div class="g-pop right-0">
                    <div class="flex flex-col px-3 py-2">
                        <span class="font-semibold">{{ $userName }}</span>
                        @if ($userEmail)
                            <span class="truncate text-[0.8125rem] text-(--g-ink-soft)">{{ $userEmail }}</span>
                        @endif
                    </div>

                    @if ($switcher)
                        <div class="flex flex-col gap-0.5 border-t border-(--g-line) pt-1" data-account-companies>
                            <p class="g-label px-3 pt-2 pb-1">Tus empresas</p>
                            <ul role="list" class="flex flex-col gap-0.5">
                                <li aria-current="true" class="flex min-h-touch items-center justify-between gap-3 rounded-[14px] bg-(--g-accent-tint) px-3 text-sm font-semibold text-(--g-accent-ink)">
                                    <span class="min-w-0 truncate">{{ $switcher['current'] }}</span>
                                    <span class="flex items-center gap-1 text-xs"><x-home.icon name="check" stroke-width="2.2" class="size-4" />Actual</span>
                                </li>
                                @foreach ($switcher['targets'] as $target)
                                    <li>
                                        <form method="POST" action="{{ route('account.switch', $target['id']) }}">
                                            @csrf
                                            <button type="submit" class="{{ $row }} w-full cursor-pointer text-left">
                                                <span class="sr-only">Cambiar a </span><span class="min-w-0 truncate">{{ $target['label'] }}</span>
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-col gap-0.5 border-t border-(--g-line) pt-1">
                        @if ($accountsHref)
                            <a href="{{ $accountsHref }}" class="{{ $row }}"><x-home.icon name="link" class="{{ $icon }}" />Cuentas vinculadas</a>
                        @endif
                        @if ($birthdayHref)
                            <a href="{{ $birthdayHref }}" class="{{ $row }}"><x-home.icon name="gift" class="{{ $icon }}" />Mi cumpleaños</a>
                        @endif
                        <x-assistant-link />
                        @if ($canAdminister)
                            <a href="{{ route('admin.index') }}" class="{{ $row }}"><x-home.icon name="settings" class="{{ $icon }}" />Administración</a>
                        @endif
                    </div>

                    {{-- resources/js/header.js shows it and keeps aria-pressed on the current choice. --}}
                    <div class="flex flex-col gap-1.5 border-t border-(--g-line) px-3 pt-2 pb-2.5" data-theme-switch hidden>
                        <span id="tema-titulo" class="g-label">Tema</span>
                        <div class="g-seg flex [&>button]:flex-1" role="group" aria-labelledby="tema-titulo">
                            <button type="button" data-theme-choice="light" aria-pressed="false">Claro</button>
                            <button type="button" data-theme-choice="dark" aria-pressed="false">Oscuro</button>
                            <button type="button" data-theme-choice="system" aria-pressed="true">Sistema</button>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('logout') }}" class="border-t border-(--g-line) pt-1" data-logout>
                        @csrf
                        <button type="submit" class="{{ $row }} w-full cursor-pointer text-left">
                            <x-home.icon name="logout" class="{{ $icon }}" />Cerrar sesión
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </div>
</header>
