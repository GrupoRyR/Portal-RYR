{{-- Mercados administration (App\Livewire\Admin\MarketManager): the inputs one company's band shows and its prices typed by hand. --}}
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
    $day = fn ($date) => str_replace('.', '', $date->locale('es')->isoFormat('D MMM YYYY'));
@endphp
<div class="flex flex-col gap-6">
    @if ($company === null)
        <p class="rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">Todavía no hay empresas. Crea una en Empresas para elegir sus mercados.</p>
    @else
        <section class="{{ $card }}" aria-labelledby="mkt-company-title">
            <h2 id="mkt-company-title" class="font-sans text-lg font-bold tracking-normal">Empresa</h2>
            <div class="flex flex-col gap-1.5 sm:max-w-sm">
                <label for="mkt-company" class="{{ $label }}">Mercados de</label>
                <select id="mkt-company" wire:model.live="companyId" class="{{ $field }}">
                    @foreach ($companies as $option)
                        <option value="{{ $option->id }}">{{ $option->short_name ?: $option->name }}@unless ($option->is_active) (inactiva)@endunless</option>
                    @endforeach
                </select>
            </div>
            <p class="{{ $hint }}">Cada empresa ve en la banda «Mercados» del inicio los indicadores para todos, los insumos que elijas aquí y sus precios ingresados a mano.</p>
        </section>

        <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

        <section class="{{ $card }}" aria-labelledby="mkt-codes-title">
            <h2 id="mkt-codes-title" class="font-sans text-lg font-bold tracking-normal">Insumos que ve esta empresa</h2>
            <form wire:submit="saveCodes" class="flex flex-col gap-[18px]">
                <fieldset class="flex flex-col gap-1">
                    <legend class="{{ $hint }} mb-2">Marca los insumos automáticos que {{ $companyName }} ve en su banda. Llegan solos cada día desde FRED (Banco de la Reserva Federal de St. Louis).</legend>
                    @foreach ($inputSeries as $series)
                        <label wire:key="code-{{ $series->code }}" class="flex min-h-touch cursor-pointer items-start gap-3 rounded-[10px] px-1 py-2 hover:bg-subtle">
                            <input type="checkbox" value="{{ $series->code }}" wire:model="codes" aria-describedby="mkt-code-{{ $series->code }}" class="mt-0.5 size-[18px] shrink-0 accent-fg">
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-[0.9375rem] font-semibold">{{ $series->label }}</span>
                                <span id="mkt-code-{{ $series->code }}" class="text-sm text-fg-muted">
                                    <span class="font-semibold text-fg-soft">{{ $series->unit->label() }} · {{ $series->frequencyLabel() }}</span>.
                                    {{ $series->meaning() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
                <div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveCodes" class="{{ $primary }}">Guardar insumos</button>
                </div>
            </form>

            <div class="flex flex-col gap-2 border-t border-line pt-4">
                <h3 class="font-sans text-base font-bold tracking-normal">Siempre se muestran</h3>
                <p class="{{ $hint }}">Todas las empresas ven estos indicadores; no se pueden quitar.</p>
                <ul class="flex flex-col gap-2" role="list">
                    @foreach ($globalSeries as $series)
                        <li wire:key="global-{{ $series->code }}" class="flex flex-col gap-0.5 px-1">
                            <span class="text-[0.9375rem] font-semibold">{{ $series->label }}</span>
                            <span class="text-sm text-fg-muted"><span class="font-semibold text-fg-soft">{{ $series->unit->label() }} · {{ $series->frequencyLabel() }}</span>. {{ $series->meaning() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        @if ($showForm)
            <section class="{{ $card }}" aria-labelledby="mkt-form-title">
                <h2 id="mkt-form-title" class="font-sans text-lg font-bold tracking-normal">{{ $editingId === null ? 'Nuevo precio' : 'Editar precio' }}</h2>
                <form wire:submit="saveInput" class="flex flex-col gap-[18px]">
                    <div class="flex flex-col gap-1.5">
                        <label for="mkt-label" class="{{ $label }}">Insumo</label>
                        <input id="mkt-label" type="text" maxlength="{{ \App\Models\MarketInput::LABEL_MAX }}" wire:model="label" placeholder="Por ejemplo, Soda cáustica" class="{{ $field }} placeholder:text-fg-muted" {!! $invalid('label', 'mkt-label-error') !!}>
                        <p class="{{ $hint }}">El mismo nombre en cada precio, así la banda muestra el último y cuánto cambió.</p>
                        @error('label') <p id="mkt-label-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-[18px] sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="mkt-value" class="{{ $label }}">Valor</label>
                            <input id="mkt-value" type="text" inputmode="decimal" autocomplete="off" wire:model="value" placeholder="Por ejemplo, 4200,50" class="{{ $field }} placeholder:text-fg-muted" {!! $invalid('value', 'mkt-value-error') !!}>
                            @error('value') <p id="mkt-value-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="mkt-unit" class="{{ $label }}">Unidad</label>
                            <select id="mkt-unit" wire:model="unit" class="{{ $field }}" {!! $invalid('unit', 'mkt-unit-error') !!}>
                                <option value="">Elige una unidad</option>
                                @foreach ($units as $unitValue => $unitLabel)
                                    <option value="{{ $unitValue }}">{{ $unitLabel }}</option>
                                @endforeach
                            </select>
                            @error('unit') <p id="mkt-unit-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="mkt-date" class="{{ $label }}">Fecha del precio</label>
                            <input id="mkt-date" type="date" max="{{ $today }}" wire:model="validOn" class="{{ $field }}" {!! $invalid('validOn', 'mkt-date-error') !!}>
                            @error('validOn') <p id="mkt-date-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label for="mkt-source" class="{{ $label }}">Fuente (opcional)</label>
                            <input id="mkt-source" type="text" maxlength="{{ \App\Models\MarketInput::SOURCE_MAX }}" wire:model="source" placeholder="Por ejemplo, Cotización de proveedor" class="{{ $field }} placeholder:text-fg-muted" {!! $invalid('source', 'mkt-source-error') !!}>
                            @error('source') <p id="mkt-source-error" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2.5">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveInput" class="{{ $primary }}">Guardar</button>
                        <button type="button" wire:click="cancelInput" class="{{ $secondary }}">Cancelar</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="{{ $card }}" aria-labelledby="mkt-list-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="mkt-list-title" class="font-sans text-lg font-bold tracking-normal">Precios ingresados a mano en {{ $companyName }}</h2>
                @unless ($showForm)
                    <button type="button" wire:click="newInput" class="{{ $primary }}">Nuevo precio</button>
                @endunless
            </div>
            <p class="{{ $hint }}">Para precios reales que ninguna fuente gratuita publica, como las cotizaciones de proveedores. La banda muestra el último precio de cada insumo con su fecha.</p>

            @if ($inputs->isEmpty())
                <p class="rounded-[14px] border border-line px-4 py-8 text-center text-[0.9375rem] text-fg-muted">{{ $companyName }} todavía no tiene precios ingresados a mano. Con «Nuevo precio» agrega el primero.</p>
            @else
                <ul class="flex flex-col divide-y divide-line rounded-[14px] border border-line">
                    @foreach ($inputs as $input)
                        <li wire:key="input-{{ $input->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 pr-1.5 pl-3 sm:flex-nowrap">
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="text-[0.9375rem] font-semibold break-words">{{ $input->label }}</span>
                                <span class="text-sm text-fg-muted break-words">{{ $day($input->valid_on) }}@if ($input->source) · {{ $input->source }}@endif</span>
                            </span>
                            <span class="shrink-0 text-[0.9375rem] font-semibold tabular-nums">{{ $input->displayValue() }}</span>
                            <span class="flex shrink-0 items-center max-sm:ml-auto">
                                <button type="button" wire:click="editInput({{ $input->id }})" aria-label="Editar el precio de {{ $input->label }} del {{ $day($input->valid_on) }}" class="{{ $iconButton }}">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>
                                </button>
                                <button type="button" wire:click="deleteInput({{ $input->id }})" wire:confirm="¿Borrar el precio de «{{ $input->label }}» del {{ $day($input->valid_on) }}?" aria-label="Borrar el precio de {{ $input->label }} del {{ $day($input->valid_on) }}" class="{{ $iconButton }} hover:text-status-failure">
                                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                                </button>
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if ($total > $inputs->count())
                    <p class="{{ $hint }}">Se muestran los {{ $inputs->count() }} precios más recientes de {{ $total }}.</p>
                @endif
            @endif
        </section>
    @endif
</div>
