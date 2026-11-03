{{-- Estado de TI administration (App\Livewire\Admin\ItServiceManager): the services one company's IT reports by hand. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-4 rounded-card border border-line bg-surface p-4 sm:p-6';
    $iconButton = 'inline-flex size-touch shrink-0 items-center justify-center rounded-[10px] text-fg-soft hover:bg-subtle hover:text-fg disabled:pointer-events-none disabled:opacity-35';
    $secondary = 'inline-flex min-h-touch items-center justify-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $primary = 'inline-flex h-11 shrink-0 items-center rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white';
    $invalid = fn (string $name, string $id) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
    $companyName = $company ? ($company->short_name ?: $company->name) : null;
    $last = $services->count() - 1;
@endphp
<div class="flex flex-col gap-6">
    @if ($company === null)
        <p class="rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">Todavía no hay empresas. Crea una en Empresas para registrar sus servicios.</p>
    @else
        <section class="{{ $card }}" aria-labelledby="its-company-title">
            <h2 id="its-company-title" class="font-sans text-lg font-bold tracking-normal">Empresa</h2>
            <div class="flex flex-col gap-1.5 sm:max-w-sm">
                <label for="its-company" class="{{ $label }}">Servicios de</label>
                <select id="its-company" wire:model.live="companyId" class="{{ $field }}">
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}">{{ $option->short_name ?: $option->name }}@unless ($option->is_active) (inactiva)@endunless</option>
                    @endforeach
                </select>
            </div>
            <p class="{{ $hint }}">Cada empresa tiene sus propios servicios. Las apps en mantenimiento o con fallas reportadas se toman solas de Aplicaciones; aquí van los demás servicios, como el correo, internet o la VPN.</p>
        </section>

        <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

        @if ($showForm)
            <section class="{{ $card }}" aria-labelledby="its-form-title">
                <h2 id="its-form-title" class="font-sans text-lg font-bold tracking-normal">{{ $editingId === null ? 'Nuevo servicio' : 'Editar servicio' }}</h2>
                <form wire:submit="saveService" class="flex flex-col gap-[18px]">
                    <div class="flex flex-col gap-1.5">
                        <label for="its-name" class="{{ $label }}">Nombre</label>
                        <input id="its-name" type="text" maxlength="{{ \App\Models\ItService::NAME_MAX }}" wire:model="name" placeholder="Por ejemplo, Correo" class="{{ $field }} placeholder:text-fg-muted" {!! $invalid('name', 'its-name-error') !!}>
                        @error('name') <p id="its-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <fieldset class="flex flex-col gap-1.5">
                        <legend class="{{ $label }} mb-1.5">Estado</legend>
                        @foreach ($statuses as $value => $statusLabel)
                            <label class="flex min-h-touch cursor-pointer items-center gap-2.5 text-[0.9375rem] font-medium">
                                <input type="radio" name="its-status" value="{{ $value }}" wire:model="status" class="size-[18px] shrink-0 accent-fg">
                                {{ $statusLabel }}
                            </label>
                        @endforeach
                        @error('status') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </fieldset>
                    <div class="flex flex-col gap-1.5">
                        <label for="its-message" class="{{ $label }}">Mensaje (opcional)</label>
                        <input id="its-message" type="text" maxlength="{{ \App\Models\ItService::MESSAGE_MAX }}" wire:model="message" placeholder="Por ejemplo, Lento en la sede norte" class="{{ $field }} placeholder:text-fg-muted" {!! $invalid('message', 'its-message-error') !!}>
                        <p class="{{ $hint }}">Texto corto, hasta {{ \App\Models\ItService::MESSAGE_MAX }} caracteres. Solo se muestra cuando el servicio no está operativo.</p>
                        @error('message') <p id="its-message-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-wrap gap-2.5">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveService" class="{{ $primary }}">Guardar</button>
                        <button type="button" wire:click="cancelService" class="{{ $secondary }}">Cancelar</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="{{ $card }}" aria-labelledby="its-list-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="its-list-title" class="font-sans text-lg font-bold tracking-normal">Servicios de {{ $companyName }}</h2>
                @unless ($showForm)
                    <button type="button" wire:click="newService" class="{{ $primary }}">Nuevo servicio</button>
                @endunless
            </div>

            @if ($services->isEmpty())
                <p class="rounded-[14px] border border-line px-4 py-8 text-center text-[0.9375rem] text-fg-muted">{{ $companyName }} todavía no tiene servicios. Con «Nuevo servicio» agrega el primero.</p>
            @else
                <ul class="flex flex-col divide-y divide-line rounded-[14px] border border-line">
                    @foreach ($services as $index => $service)
                        <li wire:key="service-{{ $service->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 pr-1.5 pl-3 sm:flex-nowrap">
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="text-[0.9375rem] font-semibold break-words">{{ $service->name }}</span>
                                @if ($service->message)
                                    <span class="text-sm text-fg-muted break-words">{{ $service->message }}</span>
                                @endif
                            </span>
                            <span @class([
                                'inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-[0.8125rem] font-semibold',
                                'bg-status-active-bg text-status-active' => $service->status === \App\Models\ItService::STATUS_OPERATIONAL,
                                'bg-status-maintenance-bg text-status-maintenance' => $service->status === \App\Models\ItService::STATUS_DEGRADED,
                                'bg-status-failure-bg text-status-failure' => $service->status === \App\Models\ItService::STATUS_DOWN,
                            ])>{{ \App\Models\ItService::statusLabel($service->status) }}</span>
                            <span class="flex shrink-0 items-center max-sm:ml-auto">
                                <button type="button" wire:click="moveService({{ $service->id }}, -1)" aria-label="Subir {{ $service->name }}" @disabled($index === 0) class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                                </button>
                                <button type="button" wire:click="moveService({{ $service->id }}, 1)" aria-label="Bajar {{ $service->name }}" @disabled($index === $last) class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <button type="button" wire:click="editService({{ $service->id }})" aria-label="Editar {{ $service->name }}" class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
                                </button>
                                <button type="button" wire:click="deleteService({{ $service->id }})" wire:confirm="¿Borrar el servicio «{{ $service->name }}»?" aria-label="Borrar {{ $service->name }}" class="{{ $iconButton }} hover:text-status-failure">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                </button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
