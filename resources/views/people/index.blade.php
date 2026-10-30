{{--
    «Personas»: the directory of the user's company (App\Http\Controllers\People\PeopleController),
    copied from Microsoft every night, with its own search. $people is a
    paginator of App\Models\DirectoryEntry, or null for an account without a
    directory. No Livewire or Alpine; each row has an anchor (#p-{id}).
--}}
@use('App\Catalog\AppSearch')
<x-layouts.app title="Personas" brand-band>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Personas</h1>
        </div>

        @if ($people === null)
            <p class="rounded-card border border-line bg-surface px-5 py-8 text-center text-[0.9375rem] text-fg-muted">El directorio de personas no está disponible para esta cuenta.</p>
        @elseif (! $searching && $people->total() === 0)
            <p class="rounded-card border border-line bg-surface px-5 py-8 text-center text-[0.9375rem] text-fg-muted">El directorio de {{ $companyName }} aún no se ha copiado desde Microsoft. Vuelve a intentarlo mañana.</p>
        @else
            <form role="search" method="GET" action="{{ route('people.index') }}" class="flex flex-col gap-2">
                <label for="buscar-personas" class="text-sm font-semibold">Buscar por nombre, cargo, área, oficina o correo</label>
                <div class="flex gap-2">
                    <input id="buscar-personas" name="q" type="search" value="{{ $query }}" maxlength="{{ AppSearch::MAX_LENGTH }}" autocomplete="off" enterkeyhint="search" aria-describedby="personas-resultados"
                           class="h-12 min-w-0 flex-1 rounded-control border border-line-strong bg-surface px-3.5 text-base">
                    <button type="submit" class="inline-flex min-h-touch shrink-0 cursor-pointer items-center gap-2 rounded-control bg-brand-button px-4 text-[0.9375rem] font-semibold text-white">
                        <x-home.icon name="search" stroke-width="2" class="size-[18px]" />Buscar
                    </button>
                </div>
            </form>

            <p id="personas-resultados" aria-live="polite" class="-mt-2 text-sm font-semibold">
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
                <a href="{{ route('people.index') }}" class="-mt-4 inline-flex min-h-touch items-center self-start text-sm font-semibold text-brand-ink underline-offset-4 hover:underline">Ver todas las personas</a>
            @endif

            @if ($people->isNotEmpty())
                <ul class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface">
                    @foreach ($people as $person)
                        <li id="p-{{ $person->id }}" class="flex scroll-mt-6 flex-col gap-1.5 px-5 py-4 target:bg-subtle md:px-6">
                            <h2 id="persona-{{ $person->id }}" class="font-sans text-base leading-snug font-bold tracking-normal break-words">{{ $person->display_name }}</h2>
                            @if ($role = $person->roleLine())
                                <p class="text-sm break-words text-fg-soft">{{ $role }}</p>
                            @endif
                            @if ($person->office_location)
                                <p class="text-sm break-words text-fg-muted"><span class="sr-only">Oficina: </span>{{ $person->office_location }}</p>
                            @endif
                            <p class="flex flex-wrap gap-x-4 gap-y-0 text-sm font-semibold">
                                @if ($phone = $person->phoneHref())
                                    <a href="{{ $phone }}" class="inline-flex min-h-touch items-center break-all text-brand-ink underline-offset-4 hover:underline"><span class="sr-only">Teléfono: </span>{{ $person->business_phone }}</a>
                                @endif
                                <a href="mailto:{{ $person->email }}" class="inline-flex min-h-touch items-center break-all text-brand-ink underline-offset-4 hover:underline"><span class="sr-only">Correo: </span>{{ $person->email }}</a>
                                <a href="{{ $person->teamsChatUrl() }}" target="_blank" rel="noopener" aria-describedby="persona-{{ $person->id }}" class="inline-flex min-h-touch items-center gap-1.5 text-brand-ink underline-offset-4 hover:underline">
                                    <x-home.icon name="teams" class="size-[18px]" />Chat en Teams
                                </a>
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($people->hasPages())
                <nav aria-label="Páginas de personas" class="flex items-center justify-between gap-3 text-sm font-semibold">
                    @if ($people->previousPageUrl())
                        <a href="{{ $people->previousPageUrl() }}" rel="prev" class="inline-flex min-h-touch items-center gap-1.5 rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">
                            <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Anterior
                        </a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-fg-muted">Página {{ $people->currentPage() }} de {{ $people->lastPage() }}</span>
                    @if ($people->nextPageUrl())
                        <a href="{{ $people->nextPageUrl() }}" rel="next" class="inline-flex min-h-touch items-center gap-1.5 rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">
                            Siguiente<x-home.icon name="chevron-right" stroke-width="2" class="size-[18px]" />
                        </a>
                    @else
                        <span></span>
                    @endif
                </nav>
            @endif
        @endif
    </main>
</x-layouts.app>
