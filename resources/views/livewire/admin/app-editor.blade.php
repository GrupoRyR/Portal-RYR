{{-- App editor (docs/diseno/AdminAppEdit.dc.html): form + status and icon aside. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $readonly = 'h-11 w-full rounded-[10px] border border-line bg-ground px-3 text-sm text-fg-soft';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6';
    $heading = 'font-sans text-lg font-bold tracking-normal';
    $choice = 'flex min-h-touch cursor-pointer items-center gap-2.5 text-[0.9375rem] font-medium';
    $check = 'size-[18px] shrink-0 accent-fg';
    $creating = $app === null;
    $adminStatus = $creating ? null : \App\View\Admin\AppAdminStatus::of($app);
    $chip = [
        'activa' => 'bg-status-active-bg text-status-active',
        'fallas' => 'bg-status-failure-bg text-status-failure',
        'mantenimiento' => 'bg-status-maintenance-bg text-status-maintenance',
        'oculta' => 'bg-status-neutral-bg text-status-neutral',
    ];
    $reported = [
        'operational' => ['Operativa', 'bg-status-active-bg text-status-active'],
        'degraded' => ['Degradada', 'bg-status-failure-bg text-status-failure'],
        'down' => ['Caída', 'bg-status-failure-bg text-status-failure'],
    ];
    $initial = mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
    $lightUrl = $app?->iconUrl();
    $darkUrl = $app?->iconUrl(dark: true) ?? $lightUrl;
    $invalid = fn (string $field, string $id) => $errors->has($field) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
@endphp
<div class="flex flex-col gap-6">
    <header class="flex flex-col gap-4">
        <nav aria-label="Ruta" class="flex items-center gap-2 text-sm text-fg-muted">
            <a href="{{ route('admin.apps.index') }}" class="font-semibold text-fg-soft underline-offset-4 hover:underline">Aplicaciones</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page" class="truncate">{{ $creating ? 'Nueva aplicación' : $app->name }}</span>
        </nav>
        <div class="flex flex-wrap items-center gap-4">
            @if ($creating)
                <span aria-hidden="true" class="flex size-14 shrink-0 items-center justify-center rounded-[16px] font-display text-2xl font-bold text-white" style="background-color: {{ $accent }}">{{ $initial }}</span>
            @else
                @include('apps.partials.icon', ['app' => $app, 'class' => 'size-14 text-2xl'])
            @endif
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <h1 class="text-[1.75rem] leading-tight font-bold sm:text-[2rem]">{{ $creating ? 'Nueva aplicación' : $app->name }}</h1>
                @unless ($creating)
                    <span class="flex flex-wrap items-center gap-2">
                        <code class="rounded-md bg-subtle px-2 py-0.5 text-[0.8125rem]">{{ $app->slug }}</code>
                        <span class="rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold {{ $chip[$adminStatus->value] }}">{{ $adminStatus->label() }}</span>
                    </span>
                @endunless
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @if (! $creating && $app->isWeb() && $app->url)
                    <a href="{{ $app->url }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-4 text-[0.9375rem] font-semibold hover:bg-subtle">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
                        Abrir la app<span class="sr-only"> (pestaña nueva)</span>
                    </a>
                @endif
                <button type="submit" form="app-form" wire:loading.attr="disabled" wire:target="save,iconLight,iconDark"
                        class="inline-flex h-11 items-center rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white disabled:opacity-60">
                    {{ $creating ? 'Crear aplicación' : 'Guardar cambios' }}
                </button>
            </div>
        </div>
    </header>

    @if (session('status'))
        <p role="status" class="rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">Revisa los campos marcados.</p>
    @endif

    <div class="grid gap-6 min-[1200px]:grid-cols-[minmax(0,1fr)_340px] min-[1200px]:items-start">
        <div class="flex min-w-0 flex-col gap-5">
            <form id="app-form" wire:submit="save" class="flex flex-col gap-5" novalidate>
                {{-- General --}}
                <section class="{{ $card }}" aria-labelledby="e-general">
                    <h2 id="e-general" class="{{ $heading }}">General</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="e-name" class="{{ $label }}">Nombre</label>
                            <input id="e-name" type="text" maxlength="120" @if ($creating) wire:model.live.debounce.400ms="name" @else wire:model="name" @endif class="{{ $field }}" {!! $invalid('name', 'e-name-error') !!}>
                            @error('name') <p id="e-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="e-slug" class="{{ $label }}">Identificador</label>
                            @if ($creating)
                                <input id="e-slug" type="text" maxlength="60" wire:model.blur="slug" spellcheck="false" autocapitalize="none" aria-describedby="e-slug-h" class="{{ $field }} font-mono text-sm" {!! $invalid('slug', 'e-slug-error') !!}>
                                <span id="e-slug-h" class="{{ $hint }}">Minúsculas y guiones, por ejemplo cuentas-por-pagar. No se puede cambiar después.</span>
                                @error('slug') <p id="e-slug-error" class="{{ $error }}">{{ $message }}</p> @enderror
                            @else
                                <input id="e-slug" type="text" value="{{ $app->slug }}" readonly aria-describedby="e-slug-h" class="{{ $readonly }} font-mono">
                                <span id="e-slug-h" class="{{ $hint }}">Fijo. Lo usan la API y el botón de Soporte TI.</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="e-desc" class="{{ $label }}">Descripción corta</label>
                        <textarea id="e-desc" rows="2" maxlength="120" wire:model="description" aria-describedby="e-desc-h" class="w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] text-fg" {!! $invalid('description', 'e-desc-error') !!}></textarea>
                        <span id="e-desc-h" class="{{ $hint }}">Hasta 120 caracteres. Se ve debajo del nombre en el inicio.</span>
                        @error('description') <p id="e-desc-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                        <div class="flex flex-col gap-1.5">
                            <label for="e-cat" class="{{ $label }}">Categoría</label>
                            <select id="e-cat" wire:model="categoryId" class="{{ $field }}" {!! $invalid('categoryId', 'e-cat-error') !!}>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('categoryId') <p id="e-cat-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="{{ $label }} mb-1.5">Empresas</legend>
                            <div class="flex flex-wrap gap-x-5">
                                @foreach ($companies as $company)
                                    <label wire:key="company-{{ $company->id }}" @class([$choice, 'opacity-60' => $allCompanies])>
                                        <input type="checkbox" wire:model="companyIds" value="{{ $company->id }}" @disabled($allCompanies) class="{{ $check }}">
                                        {{ $company->short_name ?: $company->name }}
                                        @unless ($company->is_active) <span class="text-xs text-fg-muted">(inactiva)</span> @endunless
                                    </label>
                                @endforeach
                                <label class="{{ $choice }}">
                                    <input type="checkbox" wire:model.live="allCompanies" aria-describedby="e-all-h" class="{{ $check }}">
                                    Todas las empresas activas
                                </label>
                            </div>
                            <span id="e-all-h" class="{{ $hint }}">«Todas» incluye las empresas que se agreguen después. Igual hace falta una regla en «Quién la ve».</span>
                            @error('companyIds.*') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </fieldset>
                    </div>
                </section>

                {{-- How it opens --}}
                <section class="{{ $card }}" aria-labelledby="e-open-title">
                    <h2 id="e-open-title" class="{{ $heading }}">Cómo se abre</h2>
                    <fieldset>
                        <legend class="sr-only">Tipo</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach (['web' => ['Web', 'Abre una dirección https://'], 'local' => ['Instalada en el equipo', 'Muestra una ficha con instrucciones']] as $value => [$title, $text])
                                <label @class([
                                    'flex cursor-pointer items-start gap-3 rounded-[14px] p-3.5',
                                    'border-2 border-fg' => $type === $value,
                                    'border border-line-strong' => $type !== $value,
                                ])>
                                    <input type="radio" wire:model.live="type" value="{{ $value }}" class="{{ $check }} mt-0.5">
                                    <span class="flex flex-col">
                                        <span class="text-[0.9375rem] font-semibold">{{ $title }}</span>
                                        <span class="{{ $hint }}">{{ $text }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($type === 'web')
                        <div class="flex flex-col gap-1.5">
                            <label for="e-url" class="{{ $label }}">Dirección</label>
                            <input id="e-url" type="url" inputmode="url" maxlength="2048" wire:model="url" spellcheck="false" autocapitalize="none" aria-describedby="e-url-h" class="{{ $field }} text-sm" {!! $invalid('url', 'e-url-error') !!}>
                            <span id="e-url-h" class="{{ $hint }}">Solo https:// y dominios permitidos por TI.</span>
                            @error('url') <p id="e-url-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="flex flex-col gap-1.5">
                                <label for="e-host" class="{{ $label }}">Alojamiento</label>
                                <select id="e-host" wire:model="hosting" class="{{ $field }}">
                                    @foreach (\App\Livewire\Admin\AppEditor::WEB_HOSTINGS as $value)
                                        <option value="{{ $value }}">{{ \App\View\Admin\AppAdminRow::HOSTING_LABELS[$value] }}</option>
                                    @endforeach
                                </select>
                                @error('hosting') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="e-login" class="{{ $label }}">Inicio de sesión</label>
                                <select id="e-login" wire:model="loginType" class="{{ $field }}">
                                    @foreach (\App\Livewire\Admin\AppEditor::WEB_LOGIN_TYPES as $value)
                                        <option value="{{ $value }}">{{ \App\View\Admin\AppAdminRow::LOGIN_LABELS[$value] }}</option>
                                    @endforeach
                                </select>
                                @error('loginType') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="e-open" class="{{ $label }}">Abrir en</label>
                                <select id="e-open" wire:model="openIn" class="{{ $field }}">
                                    <option value="new">Pestaña nueva</option>
                                    <option value="same">Misma pestaña</option>
                                </select>
                                @error('openIn') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @else
                        <div class="flex flex-col gap-1.5">
                            <label for="e-local" class="{{ $label }}">Instrucciones</label>
                            <textarea id="e-local" rows="4" maxlength="2000" wire:model="localInstructions" aria-describedby="e-local-h" class="w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] text-fg" {!! $invalid('localInstructions', 'e-local-error') !!}></textarea>
                            <span id="e-local-h" class="{{ $hint }}">Un paso por línea. Es lo que ve la persona al tocar la tarjeta.</span>
                            @error('localInstructions') <p id="e-local-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="e-download" class="{{ $label }}">Dirección de descarga <span class="font-normal text-fg-muted">(opcional)</span></label>
                            <input id="e-download" type="url" inputmode="url" maxlength="2048" wire:model="downloadUrl" spellcheck="false" autocapitalize="none" placeholder="https://" class="{{ $field }} text-sm" {!! $invalid('downloadUrl', 'e-download-error') !!}>
                            @error('downloadUrl') <p id="e-download-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </section>
            </form>

            {{-- Who sees it --}}
            @if ($creating)
                <section class="{{ $card }}" aria-labelledby="e-rules">
                    <h2 id="e-rules" class="{{ $heading }}">Quién la ve</h2>
                    <p class="text-[0.9375rem] text-fg-soft">Crea la aplicación para elegir quién la ve.</p>
                </section>
            @endif
        </div>

        <aside class="flex flex-col gap-5" aria-label="Estado e ícono">
            {{-- Status set by TI --}}
            <section class="{{ $card }}" aria-labelledby="e-status">
                <h2 id="e-status" class="{{ $heading }}">Estado que pone TI</h2>
                <fieldset class="flex flex-col">
                    <legend class="sr-only">Estado</legend>
                    @foreach (['active' => 'Activa', 'maintenance' => 'En mantenimiento', 'hidden' => 'Oculta'] as $value => $text)
                        <label class="{{ $choice }}">
                            <input type="radio" wire:model.live="status" value="{{ $value }}" form="app-form" class="{{ $check }}">
                            {{ $text }}
                        </label>
                    @endforeach
                </fieldset>
                @if ($status === 'maintenance')
                    <div class="flex flex-col gap-1.5">
                        <label for="e-maint" class="{{ $label }}">Mensaje para las personas</label>
                        <textarea id="e-maint" rows="3" maxlength="200" wire:model="maintenanceMessage" form="app-form" class="w-full rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] text-fg" {!! $invalid('maintenanceMessage', 'e-maint-error') !!}></textarea>
                        @error('maintenanceMessage') <p id="e-maint-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="e-until" class="{{ $label }}">Vuelve a estar disponible <span class="font-normal text-fg-muted">(opcional)</span></label>
                        <input id="e-until" type="datetime-local" wire:model="maintenanceUntil" form="app-form" class="{{ $field }}" {!! $invalid('maintenanceUntil', 'e-until-error') !!}>
                        @error('maintenanceUntil') <p id="e-until-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($status === 'hidden')
                    <p class="{{ $hint }}">Nadie la ve en el inicio. Sus registros de uso se conservan.</p>
                @endif
                <p class="rounded-[10px] bg-subtle px-3 py-2.5 text-[0.8125rem] text-fg-soft">El mantenimiento manda sobre lo que reporten otros sistemas.</p>
            </section>

            {{-- Reported by other systems --}}
            @unless ($creating)
                <section class="{{ $card }}" aria-labelledby="e-reported">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="e-reported" class="{{ $heading }}">Estado reportado</h2>
                        @if ($app->external_status)
                            @php([$reportedLabel, $reportedClass] = $reported[$app->external_status] ?? [$app->external_status, 'bg-status-neutral-bg text-status-neutral'])
                            <span class="rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold {{ $reportedClass }}">{{ $reportedLabel }}</span>
                        @endif
                    </div>
                    @if ($app->external_status || $app->external_status_message || $app->external_status_at)
                        <div class="flex flex-col gap-1 rounded-[12px] border border-line p-3.5">
                            @if ($app->external_status_message)
                                <span class="text-[0.9375rem] font-semibold">«{{ $app->external_status_message }}»</span>
                            @endif
                            @if ($app->external_status_at)
                                <span class="{{ $hint }}">Reportado {{ $app->external_status_at->diffForHumans() }}</span>
                            @endif
                        </div>
                        <button type="button" wire:click="clearExternalStatus" wire:confirm="¿Limpiar el estado reportado? La tarjeta vuelve a verse normal."
                                class="inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle">
                            Limpiar estado
                        </button>
                    @else
                        <p class="text-[0.9375rem] text-fg-soft">Ningún sistema ha reportado fallas.</p>
                    @endif
                </section>
            @endunless

            {{-- Icon --}}
            <section class="{{ $card }}" aria-labelledby="e-icon">
                <h2 id="e-icon" class="{{ $heading }}">Ícono</h2>
                <ul role="list" class="grid grid-cols-3 gap-2.5">
                    @foreach ([
                        ['Vista en fondo claro', 'bg-[#F5F5F2]', $lightUrl],
                        ['Vista en fondo oscuro', 'bg-[#121315]', $darkUrl],
                        ['Vista sobre el color de acento', '', null],
                    ] as [$previewLabel, $ground, $src])
                        <li class="flex aspect-square items-center justify-center rounded-[14px] border border-line {{ $ground }}" @if ($ground === '') style="background-color: {{ $accent }}" @endif>
                            <span class="sr-only">{{ $previewLabel }}</span>
                            @if ($src)
                                <img src="{{ $src }}" alt="" width="48" height="48" class="size-12 rounded-icon">
                            @else
                                <span aria-hidden="true" @class([
                                    'flex size-12 items-center justify-center rounded-icon font-display text-xl font-bold text-white',
                                    'ring-2 ring-white/70' => $ground === '',
                                ]) @if ($ground !== '') style="background-color: {{ $accent }}" @endif>{{ $initial }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @foreach ([
                    ['iconLight', 'Ícono claro', 'SVG cuadrado, o PNG/WebP cuadrado de 192 px o más · máx. 512 KB'],
                    ['iconDark', 'Ícono oscuro (opcional)', 'Para el modo oscuro. Si falta, se usa el claro.'],
                ] as [$property, $uploadLabel, $uploadHint])
                    <div class="flex flex-col gap-1.5">
                        <span class="{{ $label }}">{{ $uploadLabel }}</span>
                        <label for="e-{{ $property }}" class="flex min-h-touch cursor-pointer items-center justify-between gap-3 rounded-[10px] border border-dashed border-line-strong px-3 py-2 text-sm hover:bg-subtle focus-within:outline-2 focus-within:outline-fg">
                            <span class="min-w-0 truncate text-fg-soft">
                                @if ($this->{$property})
                                    Listo para guardar: {{ $this->{$property}->getClientOriginalName() }}
                                @else
                                    {{ $uploadHint }}
                                @endif
                            </span>
                            <span class="shrink-0 font-semibold">{{ ($property === 'iconLight' ? $app?->icon_path : $app?->icon_dark_path) ? 'Reemplazar' : 'Subir' }}</span>
                        </label>
                        <input id="e-{{ $property }}" type="file" wire:model="{{ $property }}" accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp" class="sr-only">
                        <span wire:loading wire:target="{{ $property }}" class="{{ $hint }}">Subiendo…</span>
                        @error($property) <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div class="flex flex-col gap-1.5">
                    <label for="e-accent" class="{{ $label }}">Color de acento</label>
                    <div class="flex items-center gap-2.5">
                        <span class="size-11 shrink-0 rounded-[10px] border border-line" style="background-color: {{ $accent }}" aria-hidden="true"></span>
                        <input id="e-accent" type="text" maxlength="7" wire:model.live.debounce.400ms="accentColor" form="app-form" spellcheck="false" autocapitalize="none" class="{{ $field }} font-mono text-sm" {!! $invalid('accentColor', 'e-accent-error') !!}>
                    </div>
                    @error('accentColor') <p id="e-accent-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </section>
        </aside>
    </div>
</div>
