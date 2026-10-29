{{-- Event editor (odd/tasks/inicio-fase-2.md WU2): what, when, where and for whom; same layout as the announcement editor. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6';
    $heading = 'font-sans text-lg font-bold tracking-normal';
    $check = 'size-[18px] shrink-0 accent-fg';
    $creating = $event === null;
    $withdrawn = $status === \App\Models\Event::STATUS_WITHDRAWN;
    $length = mb_strlen($description);
    $dateType = $allDay ? 'date' : 'datetime-local';
    $invalid = fn (string $name, string $id) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
@endphp
<div class="flex flex-col gap-6">
    <header class="flex flex-col gap-2.5">
        <nav aria-label="Ruta" class="flex items-center gap-2 text-sm text-fg-muted">
            <a href="{{ route('admin.events.index') }}" class="inline-flex min-h-touch items-center font-semibold text-fg-soft underline-offset-4 hover:underline">Eventos</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $creating ? 'Nuevo' : 'Editar' }}</span>
        </nav>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="min-w-0 flex-1 text-[1.75rem] leading-tight font-bold sm:text-[1.875rem]">{{ $creating ? 'Nuevo evento' : 'Editar evento' }}</h1>
            @if ($status)
                <x-admin.event-status :status="$status" />
            @endif
            <div class="flex flex-wrap items-center gap-2.5">
                @unless ($creating)
                    @if ($withdrawn)
                        <button type="button" wire:click="republish"
                                class="inline-flex h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-[0.9375rem] font-semibold hover:bg-subtle">
                            Volver a publicar
                        </button>
                    @else
                        <button type="button" wire:click="withdraw" wire:confirm="¿Retirar este evento? Dejará de verse en el portal de inmediato. Podrás volver a publicarlo."
                                class="inline-flex h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-[0.9375rem] font-semibold hover:bg-subtle">
                            Retirar
                        </button>
                    @endif
                @endunless
                <button type="submit" form="event-form" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex h-11 items-center rounded-control bg-brand-button px-5 text-[0.9375rem] font-semibold text-white disabled:opacity-60">
                    {{ $creating ? 'Guardar' : 'Guardar cambios' }}
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

    <form id="event-form" wire:submit="save" class="flex max-w-[760px] min-w-0 flex-col gap-5" novalidate>
        {{-- Content --}}
        <section class="{{ $card }} gap-4" aria-labelledby="e-content">
            <h2 id="e-content" class="{{ $heading }}">Evento</h2>
            <div class="flex flex-col gap-1.5">
                <label for="e-title" class="{{ $label }}">Título</label>
                <input id="e-title" type="text" maxlength="{{ \App\Models\Event::TITLE_MAX }}" wire:model="title" value="{{ $title }}" class="{{ $field }}" {!! $invalid('title', 'e-title-error') !!}>
                @error('title') <p id="e-title-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="e-description" class="{{ $label }}">Descripción <span class="font-medium text-fg-muted">(opcional)</span></label>
                <textarea id="e-description" rows="4" maxlength="{{ \App\Models\Event::DESCRIPTION_MAX }}" wire:model.live.debounce.300ms="description" aria-describedby="e-count{{ $errors->has('description') ? ' e-description-error' : '' }}"
                          @error('description') aria-invalid="true" @enderror
                          class="w-full resize-y rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] leading-normal text-fg"></textarea>
                <span class="flex items-start justify-between gap-3">
                    <span class="{{ $hint }}">Solo texto. Los saltos de línea se respetan.</span>
                    <span id="e-count" @class(['shrink-0 text-xs tabular-nums', 'text-fg-muted' => $length <= \App\Models\Event::DESCRIPTION_MAX, 'font-semibold text-status-failure' => $length > \App\Models\Event::DESCRIPTION_MAX])>{{ $length }} / {{ \App\Models\Event::DESCRIPTION_MAX }}</span>
                </span>
                @error('description') <p id="e-description-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="e-location" class="{{ $label }}">Lugar <span class="font-medium text-fg-muted">(opcional)</span></label>
                <input id="e-location" type="text" maxlength="{{ \App\Models\Event::LOCATION_MAX }}" wire:model="location" placeholder="Sala de juntas, Teams…" class="{{ $field }}" {!! $invalid('location', 'e-location-error') !!}>
                @error('location') <p id="e-location-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- When --}}
        <section class="{{ $card }}" aria-labelledby="e-when">
            <h2 id="e-when" class="{{ $heading }}">Fecha</h2>
            <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
                <span class="flex flex-col gap-0.5">
                    <span class="text-[0.9375rem] font-semibold">Todo el día</span>
                    <span id="e-all-day-h" class="{{ $hint }}">Sin hora: ocupa días completos en el calendario.</span>
                </span>
                <input type="checkbox" role="switch" wire:model.live="allDay" aria-describedby="e-all-day-h"
                       class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
            </label>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="e-from" class="{{ $label }}">{{ $allDay ? 'Primer día' : 'Empieza' }}</label>
                    <input id="e-from" type="{{ $dateType }}" wire:model="startsAt" wire:key="e-from-{{ $dateType }}" class="{{ $field }}" {!! $invalid('startsAt', 'e-from-error') !!}>
                    @error('startsAt') <p id="e-from-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="e-to" class="{{ $label }}">{{ $allDay ? 'Último día' : 'Termina' }} <span class="font-medium text-fg-muted">(opcional)</span></label>
                    <input id="e-to" type="{{ $dateType }}" wire:model="endsAt" wire:key="e-to-{{ $dateType }}" aria-describedby="e-to-h" class="{{ $field }}" {!! $invalid('endsAt', 'e-to-error') !!}>
                    <span id="e-to-h" class="{{ $hint }}">{{ $allDay ? 'Sin fecha, dura un solo día.' : 'Sin fin, el calendario lo muestra como un momento.' }}</span>
                    @error('endsAt') <p id="e-to-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="{{ $hint }}">Hora de Colombia. Se muestra en «Próximas fechas» y en Eventos hasta que termina.</p>
        </section>

        {{-- Publication --}}
        <section class="{{ $card }}" aria-labelledby="e-publication">
            <h2 id="e-publication" class="{{ $heading }}">Publicación</h2>
            <fieldset class="flex flex-col gap-2">
                <legend class="{{ $label }} mb-2">Para quién</legend>
                @if ($restricted)
                    <p class="{{ $hint }}">Como editor de anuncios, publicas eventos solo para tu empresa.</p>
                @endif
                <label @class(['flex min-h-10 items-center gap-3 text-[0.9375rem]', 'cursor-pointer' => ! $restricted, 'text-fg-muted' => $restricted])>
                    <input type="radio" wire:model.live="audience" value="{{ \App\Models\Event::AUDIENCE_ALL }}" @disabled($restricted) class="{{ $check }}">
                    Todas las empresas
                </label>
                <label @class(['flex min-h-10 items-center gap-3 text-[0.9375rem]', 'cursor-pointer' => ! $restricted])>
                    <input type="radio" wire:model.live="audience" value="{{ \App\Models\Event::AUDIENCE_COMPANIES }}" @disabled($restricted) class="{{ $check }}">
                    Empresas específicas
                </label>
                @if ($audience === \App\Models\Event::AUDIENCE_COMPANIES)
                    <div class="flex flex-wrap gap-2 pl-[30px]">
                        @foreach ($companies as $company)
                            @php($chosen = in_array((string) $company->id, array_map('strval', $companyIds), true))
                            <label wire:key="company-{{ $company->id }}" @class([
                                'flex h-11 items-center gap-2 rounded-[10px] px-3 text-sm font-semibold',
                                'cursor-pointer' => ! $restricted,
                                'border-2 border-fg' => $chosen,
                                'border border-line-strong' => ! $chosen,
                            ])>
                                <input type="checkbox" wire:model.live="companyIds" value="{{ $company->id }}" @disabled($restricted) class="{{ $check }}">
                                {{ $company->short_name ?: $company->name }}
                                @if (isset($company->is_active) && ! $company->is_active) <span class="text-xs font-normal text-fg-muted">(inactiva)</span> @endif
                            </label>
                        @endforeach
                    </div>
                    @error('companyIds') <p class="{{ $error }} pl-[30px]">{{ $message }}</p> @enderror
                    @error('companyIds.*') <p class="{{ $error }} pl-[30px]">{{ $message }}</p> @enderror
                @endif
                @error('audience') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </fieldset>
            <div class="flex flex-col gap-1.5 sm:max-w-[calc(50%-0.5rem)]">
                <label for="e-published" class="{{ $label }}">Se muestra desde</label>
                <input id="e-published" type="datetime-local" wire:model="publishedAt" aria-describedby="e-published-h" class="{{ $field }}" {!! $invalid('publishedAt', 'e-published-error') !!}>
                <span id="e-published-h" class="{{ $hint }}">Antes de esta fecha queda programado y nadie lo ve.</span>
                @error('publishedAt') <p id="e-published-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>
    </form>
</div>
