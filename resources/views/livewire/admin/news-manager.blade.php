{{-- News sources administration (App\Livewire\Admin\NewsManager): list per company and tab, with the add/edit form beside it. --}}
@php
    $secondary = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $field = 'h-11 min-w-0 flex-1 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $companyName = $company ? ($company->short_name ?: $company->name) : null;
    $editing = $editingId !== null;
@endphp
<div>
    <div class="flex flex-col gap-1">
        <h1 class="text-[2rem] leading-tight font-bold">Noticias</h1>
        <p class="text-[0.9375rem] text-fg-muted">Fuentes RSS o Atom de «Noticias» en el inicio. El portal las lee cada hora y guarda solo el título, el enlace y la fecha.</p>
    </div>

    @if ($companies->isEmpty())
        <p class="mt-5 rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">Primero registra una empresa.</p>
    @else
        @if ($companies->count() > 1)
            <div class="mt-6 flex flex-col gap-1.5">
                <label for="news-company" class="text-[0.8125rem] font-semibold">Empresa</label>
                <select id="news-company" wire:model.live="companyId" class="{{ $field }} max-w-md">
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}">{{ $option->short_name ?: $option->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <nav aria-label="Pestaña" class="mt-6 max-w-full overflow-x-auto">
            <ul class="flex w-max gap-1.5 rounded-control bg-subtle p-1">
                @foreach (\App\Models\NewsSource::TABS as $value => $label)
                    @php($isCurrent = $tab === $value)
                    <li>
                        <button type="button" wire:click="$set('tab', '{{ $value }}')"@if ($isCurrent) aria-current="page"@endif
                                @class([
                                    'inline-flex h-10 items-center rounded-[9px] px-3.5 text-sm whitespace-nowrap',
                                    'bg-surface font-bold shadow-sm' => $isCurrent,
                                    'font-semibold text-fg-soft hover:text-fg' => ! $isCurrent,
                                ])>{{ $label }} · {{ $counts[$value] ?? 0 }}</button>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="mt-5 grid grid-cols-1 gap-6 min-[1100px]:grid-cols-[minmax(0,1fr)_300px] min-[1100px]:items-start">
            <section class="flex min-w-0 flex-col gap-4" aria-labelledby="news-list-title">
                <h2 id="news-list-title" class="sr-only">Fuentes de {{ $companyName }} · {{ \App\Models\NewsSource::TABS[$tab] }}</h2>

                <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

                @if ($sources->isEmpty())
                    <p class="rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">No hay fuentes en esta pestaña.</p>
                @else
                    <ul class="flex flex-col gap-3">
                        @foreach ($sources as $source)
                            <li wire:key="news-source-{{ $source->id }}" class="flex flex-wrap items-center gap-3 rounded-[16px] border border-line bg-surface p-3 sm:flex-nowrap sm:gap-[18px] sm:p-4">
                                <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <span @class(['min-w-0 text-base font-bold break-words', 'text-fg-soft' => ! $source->active])>{{ $source->name }}</span>
                                    <span class="min-w-0 text-sm break-all text-fg-muted">{{ $source->url }}</span>
                                    <span class="text-[0.8125rem] text-fg-muted">
                                        {{ $source->last_fetched_at ? 'Última lectura '.$source->last_fetched_at->locale('es')->diffForHumans() : 'Todavía no se ha leído' }}
                                    </span>
                                    @if ($source->last_error_code)
                                        <span class="self-start rounded-lg bg-status-failure-bg px-2.5 py-1 text-[0.8125rem] font-semibold text-status-failure">{{ \App\Livewire\Admin\NewsManager::errorLabel($source->last_error_code) }}</span>
                                    @endif
                                </span>
                                <span @class([
                                    'inline-flex shrink-0 items-center rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap',
                                    'bg-status-active-bg text-status-active' => $source->active,
                                    'bg-status-neutral-bg text-status-neutral' => ! $source->active,
                                ])>{{ $source->active ? 'Activa' : 'Inactiva' }}</span>
                                <span class="flex shrink-0 flex-wrap items-center gap-2 max-sm:w-full">
                                    <button type="button" wire:click="startEditing({{ $source->id }})" class="{{ $secondary }}">Editar<span class="sr-only"> {{ $source->name }}</span></button>
                                    <button type="button" wire:click="toggleActive({{ $source->id }})" class="{{ $secondary }}">{{ $source->active ? 'Desactivar' : 'Activar' }}<span class="sr-only"> {{ $source->name }}</span></button>
                                    <button type="button" wire:click="delete({{ $source->id }})" wire:confirm="¿Borrar la fuente «{{ $source->name }}» y sus noticias?" class="{{ $secondary }} hover:text-status-failure">Borrar<span class="sr-only"> {{ $source->name }}</span></button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <aside class="flex flex-col gap-4" aria-label="{{ $editing ? 'Editar fuente' : 'Agregar fuente' }}">
                <form wire:submit="{{ $editing ? 'save' : 'create' }}" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
                    <div class="flex flex-col gap-1">
                        <h2 class="font-sans text-base font-bold tracking-normal">{{ $editing ? 'Editar fuente' : 'Agregar fuente' }}</h2>
                        @unless ($editing)
                            <p class="text-sm leading-relaxed text-fg-soft">Se agrega a {{ $companyName }} · {{ \App\Models\NewsSource::TABS[$tab] }}.</p>
                        @endunless
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="news-name" class="text-[0.8125rem] font-semibold">Nombre</label>
                        <input id="news-name" type="text" maxlength="{{ \App\Models\NewsSource::NAME_MAX }}" wire:model="name" placeholder="Por ejemplo, El Tiempo"
                               class="{{ $field }}"
                               @error('name') aria-invalid="true" aria-describedby="news-name-error" @enderror>
                        @error('name') <p id="news-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="news-url" class="text-[0.8125rem] font-semibold">Dirección del RSS o Atom</label>
                        <input id="news-url" type="url" inputmode="url" maxlength="{{ \App\News\FeedUrlPolicy::MAX_URL }}" wire:model="url" placeholder="https://"
                               class="{{ $field }}"
                               @error('url') aria-invalid="true" aria-describedby="news-url-error" @enderror>
                        @error('url') <p id="news-url-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    @if ($editing)
                        <div class="flex flex-col gap-1.5">
                            <label for="news-tab" class="text-[0.8125rem] font-semibold">Pestaña</label>
                            <select id="news-tab" wire:model="formTab" class="{{ $field }}"
                                    @error('formTab') aria-invalid="true" aria-describedby="news-tab-error" @enderror>
                                @foreach (\App\Models\NewsSource::TABS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('formTab') <p id="news-tab-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2.5">
                        <button type="submit" class="inline-flex h-11 shrink-0 items-center rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">{{ $editing ? 'Guardar' : 'Agregar' }}</button>
                        @if ($editing)
                            <button type="button" wire:click="cancelEditing" class="{{ $secondary }}">Cancelar</button>
                        @endif
                    </div>
                </form>

                <section class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-5">
                    <h2 class="font-sans text-base font-bold tracking-normal">Qué se acepta</h2>
                    <p class="text-sm leading-relaxed text-fg-soft">Direcciones https de un sitio público, sin usuario ni puerto. El portal no lee fuentes de redes internas ni documentos con DOCTYPE.</p>
                </section>
            </aside>
        </div>
    @endif
</div>
