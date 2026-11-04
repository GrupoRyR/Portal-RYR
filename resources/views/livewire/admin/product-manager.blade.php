{{-- Catalogs administration (App\Livewire\Admin\ProductManager): categories and products of one company. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $area = 'min-h-24 w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-4 rounded-card border border-line bg-surface p-4 sm:p-6';
    $iconButton = 'inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-fg disabled:pointer-events-none disabled:opacity-35';
    $secondary = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $primary = 'inline-flex h-11 shrink-0 items-center rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white';
    $invalid = fn (string $name, string $id) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
    $companyName = $company ? ($company->short_name ?: $company->name) : null;
    $lastCategory = $categories->count() - 1;
@endphp
<div class="flex flex-col gap-6">
    @if ($company === null)
        <p class="rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">Todavía no hay empresas. Crea una en Empresas para armar su catálogo.</p>
    @else
        <section class="{{ $card }}" aria-labelledby="pm-company-title">
            <h2 id="pm-company-title" class="font-sans text-lg font-bold tracking-normal">Empresa</h2>
            <div class="flex flex-col gap-1.5 sm:max-w-sm">
                <label for="pm-company" class="{{ $label }}">Catálogo de</label>
                <select id="pm-company" wire:model.live="companyId" class="{{ $field }}">
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}">{{ $option->short_name ?: $option->name }}@unless ($option->is_active) (inactiva)@endunless</option>
                    @endforeach
                </select>
            </div>
            <p class="{{ $hint }}">Cada empresa tiene sus propias categorías y productos. Solo se muestran los productos publicados.</p>
        </section>

        <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>
        @error('categories') <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p> @enderror

        @if ($showForm)
            <section class="{{ $card }}" aria-labelledby="pm-form-title">
                <h2 id="pm-form-title" class="font-sans text-lg font-bold tracking-normal">{{ $editingId === null ? 'Nuevo producto' : 'Editar producto' }}</h2>
                @if ($categories->isEmpty())
                    <p class="text-sm text-fg-soft">Primero agrega una categoría para {{ $companyName }}.</p>
                    <button type="button" wire:click="cancelProduct" class="{{ $secondary }} self-start">Cerrar</button>
                @else
                    <form wire:submit="saveProduct" class="flex flex-col gap-[18px]">
                        <div class="flex flex-col gap-1.5">
                            <label for="pm-name" class="{{ $label }}">Nombre</label>
                            <input id="pm-name" type="text" maxlength="120" wire:model="productName" class="{{ $field }}" {!! $invalid('productName', 'pm-name-error') !!}>
                            @error('productName') <p id="pm-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="pm-category" class="{{ $label }}">Categoría</label>
                            <select id="pm-category" wire:model="categoryId" class="{{ $field }}" {!! $invalid('categoryId', 'pm-category-error') !!}>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('categoryId') <p id="pm-category-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="pm-summary" class="{{ $label }}">Resumen (opcional)</label>
                            <textarea id="pm-summary" maxlength="280" wire:model="summary" class="{{ $area }}" {!! $invalid('summary', 'pm-summary-error') !!}></textarea>
                            <p class="{{ $hint }}">Texto corto y sin formato, hasta 280 caracteres.</p>
                            @error('summary') <p id="pm-summary-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="pm-link" class="{{ $label }}">Enlace (opcional)</label>
                            <input id="pm-link" type="url" inputmode="url" maxlength="500" wire:model="linkUrl" placeholder="https://" class="{{ $field }}" {!! $invalid('linkUrl', 'pm-link-error') !!}>
                            <p class="{{ $hint }}">Por ejemplo, la ficha técnica. Debe empezar por https://.</p>
                            @error('linkUrl') <p id="pm-link-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="{{ $label }}">Foto (opcional)</span>
                            @php($current = $editingId === null ? null : $categories->flatMap->products->firstWhere('id', $editingId))
                            @if ($current?->photo_path)
                                <img src="{{ $current->photoUrl() }}" alt="Foto actual de {{ $current->name }}" width="96" height="96" class="size-14 rounded-[10px] border border-line object-cover">
                            @endif
                            <label for="pm-photo" class="flex min-h-touch cursor-pointer items-center justify-between gap-3 rounded-[10px] border border-dashed border-line-strong px-3 py-2 text-sm hover:bg-subtle focus-within:outline-2 focus-within:outline-fg">
                                <span class="min-w-0 truncate text-fg-soft">
                                    @if ($photo)
                                        Listo para guardar: {{ $photo->getClientOriginalName() }}
                                    @else
                                        JPG, PNG o WebP de 200 px o más · máx. 2 MB
                                    @endif
                                </span>
                                <span class="shrink-0 font-semibold">{{ $current?->photo_path ? 'Reemplazar' : 'Subir' }}</span>
                            </label>
                            <input id="pm-photo" type="file" wire:model="photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only">
                            <span wire:loading wire:target="photo" class="{{ $hint }}">Subiendo…</span>
                            @error('photo') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            @if ($current?->photo_path)
                                <label class="flex min-h-touch cursor-pointer items-center gap-2.5 text-[0.9375rem] font-medium">
                                    <input type="checkbox" wire:model="removePhoto" class="size-[18px] shrink-0 accent-fg">
                                    Quitar la foto
                                </label>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2.5">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveProduct,photo" class="{{ $primary }}">Guardar</button>
                            <button type="button" wire:click="cancelProduct" class="{{ $secondary }}">Cancelar</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        <section class="{{ $card }}" aria-labelledby="pm-list-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="pm-list-title" class="font-sans text-lg font-bold tracking-normal">Categorías y productos de {{ $companyName }}</h2>
                @unless ($showForm)
                    <button type="button" wire:click="newProduct" class="{{ $primary }}">Nuevo producto</button>
                @endunless
            </div>
            <p class="-mt-2 text-[0.8125rem] text-fg-muted">En el inicio, cada persona ve solo los productos publicados de la empresa con la que entró.</p>

            @forelse ($categories as $index => $category)
                @php($lastProduct = $category->products->count() - 1)
                <div wire:key="category-{{ $category->id }}" class="flex flex-col rounded-[14px] border border-line">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-line bg-ground py-1.5 pr-1.5 pl-3 sm:flex-nowrap">
                        @if ($renamingId === $category->id)
                            <form wire:submit="saveRename" class="flex min-w-0 flex-1 flex-wrap items-center gap-2 py-1">
                                <label for="pm-rename" class="sr-only">Nuevo nombre de la categoría</label>
                                <input id="pm-rename" type="text" maxlength="60" wire:model="renameValue" x-init="$el.focus(); $el.select()"
                                       class="h-11 min-w-0 flex-1 rounded-[10px] border-2 border-fg bg-surface px-3 text-[0.9375rem] text-fg"
                                       {!! $invalid('renameValue', 'pm-rename-error') !!}>
                                <button type="submit" class="inline-flex min-h-touch items-center rounded-control bg-brand-button px-4 text-sm font-semibold text-white">Guardar</button>
                                <button type="button" wire:click="cancelRename" class="{{ $secondary }}">Cancelar</button>
                                @error('renameValue') <p id="pm-rename-error" class="{{ $error }} basis-full">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <h3 class="flex-1 font-sans text-[0.9375rem] font-bold tracking-normal break-words sm:min-w-0">{{ $category->name }}</h3>
                            <span class="shrink-0 text-[0.8125rem] whitespace-nowrap text-fg-muted">{{ $category->products->count() === 1 ? '1 producto' : $category->products->count().' productos' }}</span>
                            <span class="flex shrink-0 items-center max-sm:ml-auto">
                                <button type="button" wire:click="moveCategory({{ $category->id }}, -1)" aria-label="Subir la categoría {{ $category->name }}" @disabled($index === 0) class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                                </button>
                                <button type="button" wire:click="moveCategory({{ $category->id }}, 1)" aria-label="Bajar la categoría {{ $category->name }}" @disabled($index === $lastCategory) class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <button type="button" wire:click="startRename({{ $category->id }})" aria-label="Renombrar la categoría {{ $category->name }}" class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
                                </button>
                                <button type="button" wire:click="deleteCategory({{ $category->id }})" wire:confirm="¿Borrar la categoría «{{ $category->name }}»?" aria-label="Borrar la categoría {{ $category->name }}" class="{{ $iconButton }} hover:text-status-failure">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                </button>
                            </span>
                        @endif
                    </div>

                    @if ($category->products->isEmpty())
                        <p class="px-3 py-4 text-sm text-fg-muted">Sin productos todavía.</p>
                    @else
                        <ul class="flex flex-col divide-y divide-line">
                            @foreach ($category->products as $pIndex => $product)
                                <li wire:key="product-{{ $product->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 pr-1.5 pl-3 sm:flex-nowrap">
                                    @if ($product->photo_path)
                                        <img src="{{ $product->photoUrl() }}" alt="" width="48" height="48" loading="lazy" class="size-12 shrink-0 rounded-[10px] border border-line object-cover">
                                    @else
                                        <span class="flex size-12 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-muted" aria-hidden="true">
                                            <x-admin.nav-icon name="apps" class="size-6" />
                                        </span>
                                    @endif
                                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span class="text-[0.9375rem] font-semibold break-words">{{ $product->name }}</span>
                                        @if ($product->summary)
                                            <span class="text-sm text-fg-muted">{{ \Illuminate\Support\Str::limit($product->summary, 90) }}</span>
                                        @endif
                                        {{-- Why the home page does not show it (odd/tasks/observaciones.md O4). --}}
                                        @foreach (\App\Livewire\Admin\ProductManager::homeHints($product, $company) as $homeHint)
                                            <span class="text-[0.8125rem] font-semibold text-status-maintenance" data-home-hint>{{ $homeHint }}</span>
                                        @endforeach
                                    </span>
                                    <span @class([
                                        'inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-[0.8125rem] font-semibold',
                                        'bg-status-active-bg text-status-active' => $product->published,
                                        'bg-status-neutral-bg text-status-neutral' => ! $product->published,
                                    ])>{{ $product->published ? 'Publicado' : 'Oculto' }}</span>
                                    <span class="flex shrink-0 items-center max-sm:ml-auto">
                                        <button type="button" wire:click="moveProduct({{ $product->id }}, -1)" aria-label="Subir {{ $product->name }}" @disabled($pIndex === 0) class="{{ $iconButton }}">
                                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                                        </button>
                                        <button type="button" wire:click="moveProduct({{ $product->id }}, 1)" aria-label="Bajar {{ $product->name }}" @disabled($pIndex === $lastProduct) class="{{ $iconButton }}">
                                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                        </button>
                                        <button type="button" wire:click="togglePublished({{ $product->id }})" class="{{ $secondary }} mx-1" aria-label="{{ $product->published ? 'Ocultar' : 'Publicar' }} {{ $product->name }}">{{ $product->published ? 'Ocultar' : 'Publicar' }}</button>
                                        <button type="button" wire:click="editProduct({{ $product->id }})" aria-label="Editar {{ $product->name }}" class="{{ $iconButton }}">
                                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
                                        </button>
                                        <button type="button" wire:click="deleteProduct({{ $product->id }})" wire:confirm="¿Borrar el producto «{{ $product->name }}»? También se borra su foto." aria-label="Borrar {{ $product->name }}" class="{{ $iconButton }} hover:text-status-failure">
                                            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                        </button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="rounded-[14px] border border-line px-4 py-8 text-center text-[0.9375rem] text-fg-muted">{{ $companyName }} todavía no tiene categorías. Agrega la primera aquí abajo.</p>
            @endforelse

            <form wire:submit="createCategory" class="flex flex-col gap-1.5 border-t border-line pt-4">
                <label for="pm-new-category" class="{{ $label }}">Nueva categoría</label>
                <div class="flex gap-2.5">
                    <input id="pm-new-category" type="text" maxlength="60" wire:model="newCategory" placeholder="Por ejemplo, Accesorios"
                           class="h-11 min-w-0 flex-1 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted"
                           {!! $invalid('newCategory', 'pm-new-category-error') !!}>
                    <button type="submit" class="{{ $primary }}">Agregar</button>
                </div>
                @error('newCategory') <p id="pm-new-category-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </form>
        </section>
    @endif
</div>
