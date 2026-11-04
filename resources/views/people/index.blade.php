{{--
    «Personas»: the directory of the user's company (App\Http\Controllers\People\PeopleController),
    copied from Microsoft every night, with its own search. $people is a
    paginator of App\Models\DirectoryEntry, or null for an account without a
    directory. No Livewire or Alpine; each row has an anchor (#p-{id}).
    Liquid Glass (odd/tasks/liquid-glass.md G9): the search in one g-card,
    the people as rows of another, the pager in its foot.
--}}
@use('App\Catalog\AppSearch')
<x-layouts.app title="Personas" glass assistant>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1>Personas</h1>
        </div>

        @if ($people === null)
            <p class="g-card g-page__empty">El directorio de personas no está disponible para esta cuenta.</p>
        @elseif (! $searching && $people->total() === 0)
            <p class="g-card g-page__empty">El directorio de {{ $companyName }} aún no se ha copiado desde Microsoft. Vuelve a intentarlo mañana.</p>
        @else
            <div class="g-card">
                <div class="g-card__body">
                    <form role="search" aria-label="Buscar personas" method="GET" action="{{ route('people.index') }}" class="g-page__field">
                        <label for="buscar-personas">Buscar por nombre, cargo, área, oficina o correo</label>
                        <div class="g-page__search">
                            <input id="buscar-personas" name="q" type="search" value="{{ $query }}" maxlength="{{ AppSearch::MAX_LENGTH }}" autocomplete="off" enterkeyhint="search" aria-describedby="personas-resultados" class="g-input">
                            <button type="submit" class="g-btn g-btn--primary shrink-0">
                                <x-home.icon name="search" stroke-width="2" class="size-[18px]" />Buscar
                            </button>
                        </div>
                    </form>

                    <div class="flex flex-wrap items-center justify-between gap-x-4">
                        <p id="personas-resultados" aria-live="polite" class="g-page__strong text-sm">
                            @if ($searching)
                                @if ($people->total() === 0)
                                    No encontramos personas para «{{ $query }}».
                                @else
                                    {{ $people->total() === 1 ? '1 persona' : $people->total().' personas' }}
                                @endif
                            @else
                                {{ $people->total() === 1 ? '1 persona' : $people->total().' personas' }} en {{ $companyName }}
                            @endif
                        </p>

                        @if ($searching)
                            <a href="{{ route('people.index') }}" class="g-page__link">Ver todas las personas</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- The pager shows whenever there are pages, even on an empty one
                 (a stale ?page=N must still offer the way back). --}}
            @if ($people->isNotEmpty() || $people->hasPages())
                <div class="g-card">
                    @if ($people->isNotEmpty())
                        <ul class="g-page__list">
                            @foreach ($people as $person)
                                <li id="p-{{ $person->id }}" class="g-page__entry gap-1">
                                    <h2 id="persona-{{ $person->id }}" class="g-page__entry-title text-base">{{ $person->display_name }}</h2>
                                    @if ($role = $person->roleLine())
                                        <p class="g-page__text text-sm break-words">{{ $role }}</p>
                                    @endif
                                    @if ($person->office_location)
                                        <p class="g-page__fine text-sm break-words"><span class="sr-only">Oficina: </span>{{ $person->office_location }}</p>
                                    @endif
                                    <p class="g-page__links">
                                        @if ($phone = $person->phoneHref())
                                            <a href="{{ $phone }}" class="g-page__link"><span class="sr-only">Teléfono: </span>{{ $person->business_phone }}</a>
                                        @endif
                                        <a href="{{ $person->mailtoHref() }}" class="g-page__link"><span class="sr-only">Correo: </span>{{ $person->email }}</a>
                                        <a href="{{ $person->teamsChatUrl() }}" target="_blank" rel="noopener" aria-describedby="persona-{{ $person->id }}" class="g-page__link">
                                            <x-home.icon name="teams" class="size-[18px]" />Chat en Teams
                                        </a>
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($people->hasPages())
                        <nav aria-label="Páginas de personas" class="g-page__foot">
                            @if ($people->previousPageUrl())
                                <a href="{{ $people->previousPageUrl() }}" rel="prev" class="g-btn g-btn--small">
                                    <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Anterior
                                </a>
                            @else
                                <span></span>
                            @endif
                            <span>Página {{ $people->currentPage() }} de {{ $people->lastPage() }}</span>
                            @if ($people->nextPageUrl())
                                <a href="{{ $people->nextPageUrl() }}" rel="next" class="g-btn g-btn--small">
                                    Siguiente<x-home.icon name="chevron-right" stroke-width="2" class="size-[18px]" />
                                </a>
                            @else
                                <span></span>
                            @endif
                        </nav>
                    @endif
                </div>
            @endif
        @endif
    </main>
</x-layouts.app>
