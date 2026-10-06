{{-- Company editor (docs/diseno/AdminCompanies.dc.html): form + live preview aside. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $readonly = 'h-11 w-full rounded-[10px] border border-line bg-ground px-3 text-sm text-fg-soft';
    $label = 'text-[0.8125rem] font-semibold';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6';
    $ratio = fn (float $value) => number_format($value, 1, ',', '').':1';

    // Preview colors: neutral base per scheme plus the palette being edited.
    // Every value is a validated #RRGGBB, so the inline style is safe.
    $dark = $preview === 'dark';
    $previewVars = collect([
        '--p-ground' => $dark ? '#121315' : '#F5F5F2',
        '--p-surface' => $dark ? '#1C1D20' : '#FFFFFF',
        '--p-line' => $dark ? '#2C2E33' : '#E5E5E0',
        '--p-fg' => $dark ? '#F2F2EF' : '#17191C',
        '--p-muted' => $dark ? '#A3A6AD' : '#5E6168',
        '--p-tint' => $dark ? $palette->darkTint : $palette->tint,
        '--p-ink' => $dark ? $palette->darkInk : $palette->ink,
        '--p-primary' => $palette->primary,
        ...$palette->cssVariables(),
    ])->map(fn ($value, $name) => $name.':'.$value)->implode(';');
    $initial = mb_strtoupper(mb_substr(trim($shortName) ?: (trim($name) ?: '?'), 0, 1));
    $uploads = [
        ['property' => 'logoLight', 'label' => 'Logo claro', 'hint' => 'PNG, WebP o SVG · máx. 1 MB', 'path' => $company->logo_light_path, 'accept' => '.png,.webp,.svg,image/png,image/webp,image/svg+xml'],
        ['property' => 'logoDark', 'label' => 'Logo oscuro', 'hint' => 'PNG, WebP o SVG · máx. 1 MB', 'path' => $company->logo_dark_path, 'accept' => '.png,.webp,.svg,image/png,image/webp,image/svg+xml'],
        ['property' => 'icon', 'label' => 'Ícono celular', 'hint' => 'PNG de 512 × 512 px', 'path' => $company->icon_path, 'accept' => '.png,image/png'],
    ];
@endphp
<div class="grid gap-6 min-[1360px]:grid-cols-[minmax(0,1fr)_300px] min-[1360px]:items-start">
    <form id="company-form" wire:submit="save" class="flex min-w-0 flex-col gap-5" novalidate>
        @if (session('status'))
            <p role="status" class="rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">{{ session('status') }}</p>
        @endif

        @if ($confirmingDeactivation)
            <section role="alert" class="flex flex-col gap-3 rounded-card border border-status-failure/40 bg-status-failure-bg p-5">
                <h2 class="font-sans text-base font-bold tracking-normal text-status-failure">¿Desactivar {{ $company->name }}?</h2>
                <p class="text-sm text-fg">Sus usuarios no podrán entrar al portal hasta que la vuelvas a activar. No se borra ningún dato.</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="confirmDeactivation" class="inline-flex min-h-touch items-center rounded-control bg-status-failure px-4 text-sm font-semibold text-white dark:text-ground">Desactivar empresa</button>
                    <button type="button" wire:click="cancelDeactivation" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold">Cancelar</button>
                </div>
            </section>
        @endif

        {{-- Company data --}}
        <section class="{{ $card }}" aria-labelledby="c-data">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="c-data" class="font-sans text-lg font-bold tracking-normal">Datos de la empresa</h2>
                <label class="flex min-h-touch cursor-pointer items-center gap-2.5 text-sm font-semibold">
                    <input type="checkbox" role="switch" wire:model.live="isActive" aria-describedby="c-active-help"
                           class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
                    {{ $isActive ? 'Activa' : 'Inactiva' }}
                </label>
            </div>
            <p id="c-active-help" class="-mt-2 text-[0.8125rem] text-fg-muted">Una empresa inactiva no deja entrar a sus usuarios; no se borra nada.</p>
            @error('isActive') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="flex flex-col gap-1.5">
                    <label for="c-name" class="{{ $label }}">Nombre</label>
                    <input id="c-name" type="text" wire:model="name" value="{{ $name }}" class="{{ $field }}" @error('name') aria-invalid="true" aria-describedby="c-name-error" @enderror>
                    @error('name') <p id="c-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="c-short" class="{{ $label }}">Nombre corto</label>
                    <input id="c-short" type="text" wire:model.live.debounce.400ms="shortName" value="{{ $shortName }}" class="{{ $field }}" @error('shortName') aria-invalid="true" aria-describedby="c-short-error" @enderror>
                    @error('shortName') <p id="c-short-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="c-slug" class="{{ $label }}">Identificador</label>
                    <input id="c-slug" type="text" value="{{ $company->slug }}" readonly class="{{ $readonly }} font-mono">
                </div>
            </div>
        </section>

        {{-- Microsoft 365 --}}
        <section class="{{ $card }}" aria-labelledby="c-ms">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="c-ms" class="font-sans text-lg font-bold tracking-normal">Microsoft 365</h2>
                @if ($company->consent_verified_at)
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-status-active-bg px-2.5 py-1.5 text-[0.8125rem] font-semibold text-status-active">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                        Consentimiento verificado
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-status-maintenance-bg px-2.5 py-1.5 text-[0.8125rem] font-semibold text-status-maintenance">Consentimiento pendiente</span>
                @endif
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="c-tenant" class="{{ $label }}">ID de inquilino</label>
                <div class="flex gap-2">
                    <input id="c-tenant" type="text" value="{{ $company->tenant_id }}" readonly aria-describedby="c-tenant-help" class="{{ $readonly }} min-w-0 flex-1 font-mono">
                    <button type="button" wire:click="startTenantChange" aria-describedby="c-tenant-help" @disabled($changingTenant)
                            class="inline-flex min-h-touch shrink-0 items-center gap-1.5 rounded-[10px] border border-line-strong bg-surface px-3.5 text-sm font-semibold hover:bg-subtle disabled:opacity-60">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        Cambiar
                    </button>
                </div>
                <p id="c-tenant-help" class="text-[0.8125rem] text-fg-muted">Solo el súper administrador puede cambiarlo. Pide confirmar con Microsoft, queda en auditoría y avisa por correo a los administradores.</p>

                @if ($changingTenant)
                    <div role="group" aria-labelledby="c-tenant-change" class="mt-2 flex flex-col gap-4 rounded-[14px] border border-status-failure/40 bg-status-failure-bg p-4">
                        <div class="flex flex-col gap-1 text-status-failure">
                            <h3 id="c-tenant-change" class="font-sans text-[0.9375rem] font-bold tracking-normal">Cambiar la organización de Microsoft</h3>
                            <p class="text-sm">Los usuarios del inquilino actual pierden el acceso de inmediato. La empresa queda inactiva y vuelve al asistente: hará falta el consentimiento del nuevo inquilino y activarla de nuevo.</p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="c-new-tenant" class="{{ $label }}">Nuevo ID de inquilino</label>
                            <input id="c-new-tenant" type="text" wire:model="newTenantId" spellcheck="false" autocapitalize="none" placeholder="00000000-0000-0000-0000-000000000000" class="{{ $field }} font-mono text-sm">
                            @error('newTenantId') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="c-tenant-confirm" class="{{ $label }}">Escribe {{ $company->short_name }} para confirmar</label>
                            <input id="c-tenant-confirm" type="text" wire:model="tenantConfirmation" autocomplete="off" class="{{ $field }}">
                            @error('tenantConfirmation') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="changeTenant" wire:loading.attr="disabled" wire:target="changeTenant" class="inline-flex min-h-touch items-center rounded-control bg-status-failure px-4 text-sm font-semibold text-white dark:text-ground">Cambiar inquilino</button>
                            <button type="button" wire:click="cancelTenantChange" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold">Cancelar</button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex flex-col gap-2">
                <span id="c-dom" class="{{ $label }}">Dominios de correo</span>
                <ul role="list" aria-labelledby="c-dom" class="flex flex-wrap gap-2">
                    @foreach ($domains as $index => $domain)
                        <li wire:key="domain-{{ $domain }}" class="inline-flex h-9 items-center gap-1 rounded-[10px] bg-subtle pr-1 pl-3 text-sm font-semibold">
                            {{ $domain }}
                            <button type="button" wire:click="removeDomain({{ $index }})" aria-label="Quitar {{ $domain }}" class="inline-flex size-8 items-center justify-center rounded-lg text-fg-soft hover:bg-line">
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @error('domains') <p class="{{ $error }}">{{ $message }}</p> @enderror
                @foreach ($domains as $index => $domain)
                    @error('domains.'.$index) <p class="{{ $error }}">{{ $domain }}: {{ $message }}</p> @enderror
                @endforeach
                <div class="flex flex-wrap gap-2">
                    <label for="c-new-domain" class="sr-only">Nuevo dominio</label>
                    <input id="c-new-domain" type="text" inputmode="url" autocapitalize="none" spellcheck="false" placeholder="ejemplo.com"
                           wire:model="newDomain" wire:keydown.enter.prevent="addDomain"
                           class="{{ $field }} max-w-64 flex-1" @error('newDomain') aria-invalid="true" aria-describedby="c-new-domain-error" @enderror>
                    <button type="button" wire:click="addDomain" class="inline-flex min-h-touch items-center rounded-[10px] border border-dashed border-line-strong px-3.5 text-sm font-semibold text-fg-soft hover:bg-subtle">+ Agregar dominio</button>
                </div>
                @error('newDomain') <p id="c-new-domain-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Quick links --}}
        <section class="{{ $card }}" aria-labelledby="c-links">
            <div class="flex flex-col gap-1">
                <h2 id="c-links" class="font-sans text-lg font-bold tracking-normal">Enlaces rápidos</h2>
                <p class="text-[0.8125rem] text-fg-muted">Accesos propios de la empresa, como su SharePoint. Hasta {{ \App\Livewire\Admin\CompanyEditor::MAX_QUICK_LINKS }}, solo direcciones https://.</p>
            </div>
            @error('quickLinks') <p class="{{ $error }}">{{ $message }}</p> @enderror
            @foreach ($quickLinks as $index => $link)
                <fieldset wire:key="link-{{ $index }}" class="grid gap-2 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto] sm:items-start">
                    <legend class="sr-only">Enlace {{ $index + 1 }}</legend>
                    <div class="flex flex-col gap-1">
                        <label for="c-link-label-{{ $index }}" class="{{ $label }}">Nombre</label>
                        <input id="c-link-label-{{ $index }}" type="text" wire:model="quickLinks.{{ $index }}.label" class="{{ $field }}">
                        @error('quickLinks.'.$index.'.label') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="c-link-url-{{ $index }}" class="{{ $label }}">Dirección</label>
                        <input id="c-link-url-{{ $index }}" type="url" inputmode="url" wire:model="quickLinks.{{ $index }}.url" class="{{ $field }}">
                        @error('quickLinks.'.$index.'.url') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <button type="button" wire:click="removeQuickLink({{ $index }})" class="inline-flex min-h-touch items-center justify-center self-end rounded-[10px] px-3 text-sm font-semibold text-fg-soft hover:bg-subtle">
                        Quitar<span class="sr-only"> enlace {{ $index + 1 }}</span>
                    </button>
                </fieldset>
            @endforeach
            <div>
                <button type="button" wire:click="addQuickLink" @disabled(count($quickLinks) >= \App\Livewire\Admin\CompanyEditor::MAX_QUICK_LINKS)
                        class="inline-flex min-h-touch items-center rounded-[10px] border border-dashed border-line-strong px-3.5 text-sm font-semibold text-fg-soft hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-60">+ Agregar enlace</button>
            </div>
        </section>

        {{-- Brand --}}
        <section class="{{ $card }}" aria-labelledby="c-brand">
            <h2 id="c-brand" class="font-sans text-lg font-bold tracking-normal">Marca</h2>

            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ($uploads as $upload)
                    @php($property = $upload['property'])
                    <div class="flex flex-col gap-1.5">
                        <label for="c-{{ $property }}"
                               class="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1.5 rounded-control border border-dashed border-line-strong bg-ground p-3 text-center text-[0.8125rem] text-fg-soft hover:bg-subtle has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-fg">
                            <span class="text-sm font-semibold text-fg">{{ $upload['label'] }}</span>
                            @if ($this->{$property})
                                <span class="break-all">Nuevo: {{ $this->{$property}->getClientOriginalName() }}</span>
                            @elseif ($upload['path'])
                                <img src="{{ $company->brandFileUrl($upload['path']) }}" alt="{{ $upload['label'] }} actual de {{ $company->name }}" class="max-h-10 max-w-full object-contain">
                            @else
                                <span>{{ $upload['hint'] }}</span>
                            @endif
                            <span wire:loading wire:target="{{ $property }}" class="font-semibold">Subiendo…</span>
                            <input id="c-{{ $property }}" type="file" wire:model="{{ $property }}" accept="{{ $upload['accept'] }}" class="sr-only">
                        </label>
                        @error($property) <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (['colorPrimary' => 'Principal', 'colorAccent' => 'Acento', 'colorDark' => 'Oscuro'] as $property => $text)
                    @php($valid = preg_match('/\A#[0-9A-Fa-f]{6}\z/', $this->{$property}) === 1)
                    <div class="flex flex-col gap-1.5">
                        <label for="c-{{ $property }}" class="{{ $label }}">{{ $text }}</label>
                        <div class="flex items-center gap-2 rounded-[10px] border border-line-strong bg-surface pr-3 pl-1.5">
                            <input type="color" wire:model.live="{{ $property }}" value="{{ $valid ? strtolower($this->{$property}) : '#000000' }}" aria-label="Elegir color {{ mb_strtolower($text) }}"
                                   class="size-8 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                            <input id="c-{{ $property }}" type="text" wire:model.live.debounce.400ms="{{ $property }}" value="{{ $this->{$property} }}" maxlength="7" spellcheck="false" autocapitalize="characters"
                                   class="h-11 min-w-0 flex-1 bg-transparent font-mono text-sm uppercase focus-visible:outline-none" @error($property) aria-invalid="true" @enderror>
                        </div>
                        @error($property) <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div aria-live="polite">
                @if ($primaryRatio !== null && $primaryRatio < \App\Branding\Contrast::AA_NORMAL)
                    <div class="flex gap-3 rounded-[14px] bg-status-maintenance-bg p-4 text-sm leading-relaxed text-status-maintenance">
                        <svg class="mt-0.5 size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.01"/></svg>
                        <p class="flex flex-col gap-1">
                            <span><strong>El principal no sirve como fondo de botón:</strong> con texto blanco llega a {{ $ratio($primaryRatio) }} y se necesita 4,5:1.</span>
                            <span>Lo usaremos en estrellas, bordes y detalles, y para los botones el tono más oscuro <strong class="font-mono">{{ $palette->button }}</strong> ({{ $ratio($buttonRatio) }}).</span>
                        </p>
                    </div>
                @endif
            </div>
        </section>
    </form>

    {{-- Live preview: the colors being edited, not the saved ones. --}}
    <aside aria-labelledby="c-preview" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 min-[1360px]:sticky min-[1360px]:top-8">
        <h2 id="c-preview" class="font-sans text-sm font-bold tracking-normal text-fg-muted">Vista previa</h2>

        <div aria-hidden="true" style="{{ $previewVars }}"
             @class([
                 'overflow-hidden border border-(--p-line) bg-(--p-ground) text-(--p-fg) transition-[max-width]',
                 'mx-auto w-full max-w-[200px] rounded-[26px]' => $preview === 'phone',
                 'w-full rounded-[14px]' => $preview !== 'phone',
             ])>
            <div class="flex items-center gap-2 border-b border-(--p-line) bg-(--p-surface) px-3 py-2.5">
                <span class="flex size-6 items-center justify-center rounded-md bg-(--brand-button) font-display text-xs font-bold text-white">{{ $initial }}</span>
                <span class="truncate font-display text-[0.8125rem] font-bold">{{ trim($name) ?: 'Empresa' }}</span>
            </div>
            <div class="flex flex-col gap-2.5 p-3">
                <span class="text-[0.6875rem] text-(--p-muted)">Lunes, 5 de octubre</span>
                <span class="font-display text-base leading-tight font-bold">Buenos días, Jhon</span>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['Toma Pedidos SAP', 'Cuentas por pagar'] as $index => $app)
                        <div class="flex flex-col gap-2 rounded-xl border border-(--p-line) bg-(--p-surface) p-2.5">
                            <div class="flex items-start justify-between">
                                <span class="size-6 rounded-lg bg-(--p-tint)"></span>
                                @if ($index === 0)
                                    <svg class="size-3" viewBox="0 0 24 24" fill="var(--p-primary)" stroke="var(--p-primary)" stroke-width="1.6" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>
                                @endif
                            </div>
                            <span class="text-[0.6875rem] leading-tight font-bold">{{ $app }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="rounded-lg bg-(--p-tint) px-2.5 py-2 text-[0.6875rem] font-semibold text-(--p-ink)">Anuncio fijado</div>
                <div class="rounded-lg bg-(--brand-button) py-2 text-center text-[0.75rem] font-semibold text-white">Botón principal</div>
            </div>
        </div>

        <div role="group" aria-label="Modo de vista previa" class="grid grid-cols-3 gap-1 rounded-control bg-subtle p-1">
            @foreach (['light' => 'Claro', 'dark' => 'Oscuro', 'phone' => 'Celular'] as $mode => $text)
                <button type="button" wire:click="setPreview('{{ $mode }}')" aria-pressed="{{ $preview === $mode ? 'true' : 'false' }}"
                        @class([
                            'min-h-touch rounded-[10px] text-sm font-semibold',
                            'bg-surface text-fg shadow-sm' => $preview === $mode,
                            'text-fg-soft hover:text-fg' => $preview !== $mode,
                        ])>{{ $text }}</button>
            @endforeach
        </div>

        <div class="flex flex-col gap-2 border-t border-line pt-4">
            <button type="submit" form="company-form" wire:loading.attr="disabled" wire:target="save,confirmDeactivation"
                    class="inline-flex h-12 items-center justify-center rounded-control bg-brand-button font-semibold text-white disabled:opacity-70">
                <span wire:loading.remove wire:target="save,confirmDeactivation">Guardar cambios</span>
                <span wire:loading wire:target="save,confirmDeactivation">Guardando…</span>
            </button>
            @if ($company->updated_at)
                <p class="text-center text-xs text-fg-muted">Último cambio: {{ $company->updated_at->timezone(config('app.timezone'))->locale('es')->isoFormat('D MMM YYYY, HH:mm') }}</p>
            @endif
        </div>
    </aside>
</div>
