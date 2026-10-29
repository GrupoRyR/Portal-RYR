{{--
    The «Ctrl K» search of the home screen (odd/tasks/inicio-fase-1.md WU4):
    a modal <dialog> over the index embedded as JSON (App\Search\HomeSearchIndex),
    which resources/js/home.js filters and renders as a grouped listbox: the
    input is a combobox and the active result goes in aria-activedescendant.
    A closed <dialog> shows nothing, so without JavaScript the page keeps its
    plain GET search. toJson() escapes <, > and &: no title can end the script.
--}}
@props(['index'])
@use('App\Catalog\AppSearch')
@php
    $groups = [
        'apps' => ['label' => 'Aplicaciones', 'icon' => 'grid'],
        'announcements' => ['label' => 'Anuncios', 'icon' => 'megaphone'],
        'dates' => ['label' => 'Fechas', 'icon' => 'calendar'],
    ];
@endphp
{{-- Safe unescaped output: HomeSearchIndex::toJson() escapes every tag character. --}}
<script type="application/json" id="search-index">{!! $index->toJson() !!}</script>
<dialog id="busqueda" aria-labelledby="busqueda-titulo" data-search-dialog class="m-auto mt-4 w-[min(40rem,calc(100vw-2rem))] max-w-none overflow-hidden rounded-card border border-line bg-surface p-0 text-fg shadow-card backdrop:bg-black/50 md:mt-[10vh]">
    <h2 id="busqueda-titulo" class="sr-only">Buscar en el portal</h2>
    <div class="flex items-center gap-3 border-b border-line pr-2 pl-4">
        <x-home.icon name="search" stroke-width="2" class="size-5 text-fg-muted" />
        <label for="busqueda-texto" class="sr-only">Buscar aplicaciones, anuncios y fechas</label>
        <input id="busqueda-texto" type="text" role="combobox" aria-expanded="false" aria-controls="busqueda-resultados" aria-autocomplete="list" aria-describedby="busqueda-estado" autocomplete="off" spellcheck="false" enterkeyhint="go" maxlength="{{ AppSearch::MAX_LENGTH }}" placeholder="Buscar aplicaciones, anuncios y fechas" data-search-input class="h-14 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-fg-muted">
        <button type="button" data-search-close class="inline-flex min-h-touch shrink-0 cursor-pointer items-center gap-2 rounded-control px-3 text-sm font-semibold text-brand-ink hover:bg-subtle">
            Cerrar<kbd class="hidden rounded-md border border-line-strong px-1.5 font-sans text-xs text-fg-muted md:inline">Esc</kbd>
        </button>
    </div>
    <div id="busqueda-resultados" role="listbox" aria-label="Resultados de la búsqueda" data-search-results hidden class="max-h-[min(60dvh,32rem)] overflow-y-auto p-2">
        @foreach ($groups as $key => $group)
            <ul role="group" aria-labelledby="busqueda-grupo-{{ $key }}" data-search-group="{{ $key }}" hidden class="flex flex-col py-1">
                <li role="presentation" id="busqueda-grupo-{{ $key }}" class="px-3 pt-2 pb-1 text-xs font-bold text-fg-muted">{{ $group['label'] }}</li>
                <template>
                    <li role="option" aria-selected="false" class="flex min-h-touch items-center gap-3 rounded-control px-3 py-2 hover:bg-subtle aria-selected:bg-subtle data-link:cursor-pointer">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-brand-tint text-brand-ink">
                            <x-home.icon :name="$group['icon']" class="size-[18px]" />
                        </span>
                        <span class="flex min-w-0 flex-col">
                            <span data-title class="truncate text-sm font-semibold"></span>
                            <span data-detail class="truncate text-xs text-fg-muted"></span>
                        </span>
                    </li>
                </template>
            </ul>
        @endforeach
    </div>
    <p id="busqueda-estado" role="status" data-search-status class="px-5 py-3 text-sm text-fg-muted">Escribe para buscar aplicaciones, anuncios y fechas.</p>
</dialog>
