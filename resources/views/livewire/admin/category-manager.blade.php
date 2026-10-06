{{-- Categories administration (docs/diseno/AdminCategories.dc.html). --}}
@php
    $iconButton = 'inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-fg disabled:pointer-events-none disabled:opacity-35';
    $secondary = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $count = fn (int $n) => match ($n) { 0 => null, 1 => '1 app', default => "{$n} apps" };
    $last = $visible->count() - 1;
@endphp
<div class="grid grid-cols-1 gap-6 min-[1100px]:grid-cols-[minmax(0,1fr)_300px] min-[1100px]:items-start">
    <section class="flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface p-4 sm:p-6" aria-labelledby="cat-list-title">
        <h2 id="cat-list-title" class="sr-only">Categorías en orden</h2>

        <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>
        @error('categories') <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p> @enderror

        <ol aria-labelledby="cat-list-title" class="flex flex-col divide-y divide-line rounded-[14px] border border-line">
            @foreach ($visible as $index => $category)
                <li wire:key="category-{{ $category->id }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 pr-1.5 pl-3 sm:flex-nowrap">
                    <svg class="size-5 shrink-0 text-fg-muted" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>

                    @if ($renamingId === $category->id)
                        <form wire:submit="saveRename" class="flex min-w-0 flex-1 flex-wrap items-center gap-2 py-1">
                            <label for="cat-edit" class="sr-only">Nuevo nombre de la categoría</label>
                            <input id="cat-edit" type="text" maxlength="60" wire:model="renameValue" x-init="$el.focus(); $el.select()"
                                   class="h-11 min-w-0 flex-1 rounded-[10px] border-2 border-fg bg-surface px-3 text-[0.9375rem] text-fg"
                                   @error('renameValue') aria-invalid="true" aria-describedby="cat-edit-error" @enderror>
                            <button type="submit" class="inline-flex min-h-touch items-center rounded-control bg-fg px-4 text-sm font-semibold text-on-fg">Guardar</button>
                            <button type="button" wire:click="cancelRename" class="{{ $secondary }}">Cancelar</button>
                            @error('renameValue') <p id="cat-edit-error" class="{{ $error }} basis-full">{{ $message }}</p> @enderror
                        </form>
                    @else
                        <span class="flex-1 text-[0.9375rem] font-semibold break-words sm:min-w-0 sm:truncate">{{ $category->name }}</span>
                        <span class="shrink-0 text-[0.8125rem] whitespace-nowrap text-fg-muted">
                            @if ($category->apps_count === 0)
                                <span class="inline-flex items-center gap-1 rounded-md bg-subtle px-1.5 py-0.5 font-semibold">Vacía</span>
                            @else
                                {{ $count($category->apps_count) }}
                            @endif
                        </span>
                        <span class="flex shrink-0 items-center max-sm:ml-auto">
                            <button type="button" wire:click="moveUp({{ $category->id }})" aria-label="Subir {{ $category->name }}" @disabled($index === 0) class="{{ $iconButton }}">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                            </button>
                            <button type="button" wire:click="moveDown({{ $category->id }})" aria-label="Bajar {{ $category->name }}" @disabled($index === $last) class="{{ $iconButton }}">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <button type="button" wire:click="startRename({{ $category->id }})" aria-label="Renombrar {{ $category->name }}" class="{{ $iconButton }}">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
                            </button>
                            <button type="button" wire:click="delete({{ $category->id }})" wire:confirm="¿Borrar la categoría «{{ $category->name }}»?" aria-label="Borrar {{ $category->name }}" class="{{ $iconButton }} hover:text-status-failure">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                            </button>
                        </span>
                    @endif
                </li>
            @endforeach

            @if (! $showEmpty && $empty->isNotEmpty())
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-ground py-1.5 pr-1.5 pl-3">
                    <span class="min-w-0 flex-1 text-[0.9375rem] text-fg-soft">{{ $empty->pluck('name')->implode(' · ') }}</span>
                    <span class="shrink-0 text-[0.8125rem] font-semibold text-fg-muted">{{ $empty->count() === 1 ? '1 vacía' : $empty->count().' vacías' }}</span>
                    <button type="button" wire:click="toggleEmpty" aria-expanded="false" class="inline-flex min-h-touch shrink-0 items-center rounded-[10px] px-3 text-sm font-semibold text-brand-ink hover:bg-subtle">Mostrar</button>
                </li>
            @endif
        </ol>

        @if ($showEmpty && $empty->isNotEmpty())
            <button type="button" wire:click="toggleEmpty" aria-expanded="true" class="inline-flex min-h-touch items-center self-start rounded-[10px] px-3 text-sm font-semibold text-brand-ink hover:bg-subtle">Ocultar vacías</button>
        @endif

        <form wire:submit="create" class="flex flex-col gap-1.5 border-t border-line pt-4">
            <label for="cat-new" class="text-[0.8125rem] font-semibold">Nueva categoría</label>
            <div class="flex gap-2.5">
                <input id="cat-new" type="text" maxlength="60" wire:model="newName" placeholder="Por ejemplo, Recursos Humanos"
                       class="h-11 min-w-0 flex-1 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted"
                       @error('newName') aria-invalid="true" aria-describedby="cat-new-error" @enderror>
                <button type="submit" class="inline-flex h-11 shrink-0 items-center rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">Agregar</button>
            </div>
            @error('newName') <p id="cat-new-error" class="{{ $error }}">{{ $message }}</p> @enderror
        </form>
    </section>

    <aside class="flex flex-col gap-4" aria-label="Notas">
        <section class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-5">
            <h2 class="font-sans text-base font-bold tracking-normal">Las vacías no se muestran</h2>
            <p class="text-sm leading-relaxed text-fg-soft">Si una persona no tiene apps de una categoría, tampoco la ve. Igual con las categorías sin ninguna app.</p>
        </section>
        <section class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-5">
            <h2 class="font-sans text-base font-bold tracking-normal">Borrar una categoría</h2>
            <p class="text-sm leading-relaxed text-fg-soft">Solo se puede si está vacía. Si tiene apps, primero muévelas a otra.</p>
        </section>
    </aside>
</div>
