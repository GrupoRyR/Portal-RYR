{{-- Announcement editor (docs/diseno/AdminAnnouncementEdit.dc.html): content and publication, with a live preview. --}}
@php
    $field = 'h-11 w-full rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex flex-col gap-[18px] rounded-card border border-line bg-surface p-5 sm:p-6';
    $heading = 'font-sans text-lg font-bold tracking-normal';
    $check = 'size-[18px] shrink-0 accent-fg';
    $creating = $announcement === null;
    $withdrawn = $status === \App\Models\Announcement::STATUS_WITHDRAWN;
    $length = mb_strlen($body);
    $imageUrl = $newImageUrl ?? $currentImageUrl;
    $invalid = fn (string $name, string $id) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="'.$id.'"' : '';
@endphp
<div class="flex flex-col gap-6">
    <header class="flex flex-col gap-2.5">
        <nav aria-label="Ruta" class="flex items-center gap-2 text-sm text-fg-muted">
            <a href="{{ route('admin.announcements.index') }}" class="inline-flex min-h-touch items-center font-semibold text-fg-soft underline-offset-4 hover:underline">Anuncios</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $creating ? 'Nuevo' : 'Editar' }}</span>
        </nav>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="min-w-0 flex-1 text-[1.75rem] leading-tight font-bold sm:text-[1.875rem]">{{ $creating ? 'Nuevo anuncio' : 'Editar anuncio' }}</h1>
            @if ($status)
                <x-admin.announcement-status :status="$status" />
            @endif
            <div class="flex flex-wrap items-center gap-2.5">
                @unless ($creating)
                    @if ($withdrawn)
                        <button type="button" wire:click="republish"
                                class="inline-flex h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-[0.9375rem] font-semibold hover:bg-subtle">
                            Volver a publicar
                        </button>
                    @else
                        <button type="button" wire:click="withdraw" wire:confirm="¿Retirar este anuncio? Dejará de verse en el inicio de inmediato. Podrás volver a publicarlo."
                                class="inline-flex h-11 items-center rounded-control border border-line-strong bg-surface px-4 text-[0.9375rem] font-semibold hover:bg-subtle">
                            Retirar
                        </button>
                    @endif
                @endunless
                <button type="submit" form="announcement-form" wire:loading.attr="disabled" wire:target="save,image"
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

    <div class="grid grid-cols-1 gap-6 min-[1200px]:grid-cols-[minmax(0,1fr)_380px] min-[1200px]:items-start">
        <form id="announcement-form" wire:submit="save" class="flex min-w-0 flex-col gap-5" novalidate>
            {{-- Content --}}
            <section class="{{ $card }} gap-4" aria-labelledby="n-content">
                <h2 id="n-content" class="{{ $heading }}">Contenido</h2>
                <div class="flex flex-col gap-1.5">
                    <label for="n-title" class="{{ $label }}">Título</label>
                    <input id="n-title" type="text" maxlength="{{ \App\Models\Announcement::TITLE_MAX }}" wire:model.live.debounce.300ms="title" class="{{ $field }}" {!! $invalid('title', 'n-title-error') !!}>
                    @error('title') <p id="n-title-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="n-body" class="{{ $label }}">Texto</label>
                    <textarea id="n-body" rows="4" maxlength="{{ \App\Models\Announcement::BODY_MAX }}" wire:model.live.debounce.300ms="body" aria-describedby="n-count{{ $errors->has('body') ? ' n-body-error' : '' }}"
                              @error('body') aria-invalid="true" @enderror
                              class="w-full resize-y rounded-[10px] border border-line-strong bg-surface px-3 py-2.5 text-[0.9375rem] leading-normal text-fg"></textarea>
                    <span class="flex items-start justify-between gap-3">
                        <span class="{{ $hint }}">Solo texto. Los saltos de línea se respetan.</span>
                        <span id="n-count" @class(['shrink-0 text-xs tabular-nums', 'text-fg-muted' => $length <= \App\Models\Announcement::BODY_MAX, 'font-semibold text-status-failure' => $length > \App\Models\Announcement::BODY_MAX])>{{ $length }} / {{ \App\Models\Announcement::BODY_MAX }}</span>
                    </span>
                    @error('body') <p id="n-body-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-1.5">
                    <span class="{{ $label }}">Imagen <span class="font-medium text-fg-muted">(opcional)</span></span>
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($imageUrl)
                            <img src="{{ $imageUrl }}" alt="" width="160" height="90" class="aspect-video w-40 shrink-0 rounded-[10px] bg-subtle object-cover">
                        @else
                            <span class="flex aspect-video w-40 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-muted" aria-hidden="true">
                                <x-admin.nav-icon name="megaphone" class="size-6" />
                            </span>
                        @endif
                        <div class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <label for="n-image" class="inline-flex h-10 cursor-pointer items-center rounded-[10px] border border-line-strong bg-surface px-3.5 text-sm font-semibold hover:bg-subtle focus-within:outline-2 focus-within:outline-fg">
                                    {{ $imageUrl ? 'Cambiar' : 'Subir imagen' }}
                                    <input id="n-image" type="file" wire:model="image" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="sr-only">
                                </label>
                                @if ($imageUrl || $image)
                                    <button type="button" wire:click="clearImage" class="inline-flex h-10 items-center rounded-[10px] px-3.5 text-sm font-semibold text-status-failure hover:bg-status-failure-bg">Quitar</button>
                                @endif
                            </div>
                            <span class="{{ $hint }}">PNG, JPG o WebP de hasta 2 MB. Se convierte a WebP en proporción 16:9.</span>
                            <span wire:loading wire:target="image" class="{{ $hint }}">Subiendo…</span>
                        </div>
                    </div>
                    @error('image') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- Publication --}}
            <section class="{{ $card }}" aria-labelledby="n-publication">
                <h2 id="n-publication" class="{{ $heading }}">Publicación</h2>
                <fieldset class="flex flex-col gap-2">
                    <legend class="{{ $label }} mb-2">Para quién</legend>
                    @if ($restricted)
                        <p class="{{ $hint }}">Como editor de anuncios, publicas solo para tu empresa.</p>
                    @endif
                    <label @class(['flex min-h-10 items-center gap-3 text-[0.9375rem]', 'cursor-pointer' => ! $restricted, 'text-fg-muted' => $restricted])>
                        <input type="radio" wire:model.live="audience" value="{{ \App\Models\Announcement::AUDIENCE_ALL }}" @disabled($restricted) class="{{ $check }}">
                        Todas las empresas
                    </label>
                    <label @class(['flex min-h-10 items-center gap-3 text-[0.9375rem]', 'cursor-pointer' => ! $restricted])>
                        <input type="radio" wire:model.live="audience" value="{{ \App\Models\Announcement::AUDIENCE_COMPANIES }}" @disabled($restricted) class="{{ $check }}">
                        Empresas específicas
                    </label>
                    @if ($audience === \App\Models\Announcement::AUDIENCE_COMPANIES)
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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <label for="n-from" class="{{ $label }}">Desde</label>
                        <input id="n-from" type="datetime-local" wire:model.live="startsAt" class="{{ $field }}" {!! $invalid('startsAt', 'n-from-error') !!}>
                        @error('startsAt') <p id="n-from-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="n-to" class="{{ $label }}">Hasta <span class="font-medium text-fg-muted">(opcional)</span></label>
                        <input id="n-to" type="datetime-local" wire:model="endsAt" aria-describedby="n-to-h" class="{{ $field }}" {!! $invalid('endsAt', 'n-to-error') !!}>
                        <span id="n-to-h" class="{{ $hint }}">Sin fecha, se muestra hasta que lo retires.</span>
                        @error('endsAt') <p id="n-to-error" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-[0.9375rem] font-semibold">Fijar arriba</span>
                        <span id="n-pin-h" class="{{ $hint }}">Se muestra primero y destacado con el color de la empresa.</span>
                    </span>
                    <input type="checkbox" role="switch" wire:model.live="isPinned" aria-describedby="n-pin-h"
                           class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
                </label>
                <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-[0.9375rem] font-semibold">Lectura obligatoria</span>
                        <span id="n-read-h" class="{{ $hint }}">Cada cuenta que lo ve confirma que lo leyó. Aquí verás cuáles faltan.</span>
                    </span>
                    <input type="checkbox" role="switch" wire:model.live="requiresRead" aria-describedby="n-read-h"
                           class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
                </label>
            </section>
        </form>

        {{-- Live preview: the same partials as the home screen. --}}
        <aside aria-labelledby="n-preview" x-data="{ device: 'desktop' }" class="flex flex-col gap-3 min-[1200px]:sticky min-[1200px]:top-8">
            <div class="flex items-center justify-between gap-3">
                <h2 id="n-preview" class="font-sans text-sm font-bold tracking-normal text-fg-muted">Vista previa</h2>
                <div role="group" aria-label="Dispositivo" class="flex gap-1 rounded-[10px] bg-subtle p-[3px]">
                    @foreach (['desktop' => 'Escritorio', 'phone' => 'Celular'] as $device => $deviceLabel)
                        <button type="button" x-on:click="device = '{{ $device }}'"
                                aria-pressed="{{ $device === 'desktop' ? 'true' : 'false' }}" x-bind:aria-pressed="(device === '{{ $device }}').toString()"
                                class="h-9 rounded-lg px-3 text-[0.8125rem] font-semibold text-fg-soft aria-pressed:bg-surface aria-pressed:font-bold aria-pressed:text-fg">{{ $deviceLabel }}</button>
                    @endforeach
                </div>
            </div>
            <div x-show="device === 'desktop'" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5" aria-hidden="true">
                <span class="font-display text-xl font-bold">Anuncios</span>
                <x-announcements.card :card="$preview" heading="span" />
            </div>
            <div x-show="device === 'phone'" x-cloak class="mx-auto w-full max-w-[375px] rounded-[28px] border border-line bg-ground p-4" aria-hidden="true">
                <x-announcements.banner :card="$preview" />
            </div>
            <p class="{{ $hint }}" x-text="device === 'desktop' ? 'Tarjeta del lateral del inicio en escritorio.' : 'Franja del inicio en celular y tablet.'">Tarjeta del lateral del inicio en escritorio.</p>
        </aside>
    </div>

    {{-- Mandatory reading: only for people who may edit it (AnnouncementPolicy::viewReads). --}}
    @if ($reads)
        @php($missing = $reads['total'] - $reads['read'])
        <section class="{{ $card }} gap-3" aria-labelledby="n-reads" data-read-report>
            <h2 id="n-reads" class="{{ $heading }}">Lectura</h2>
            <p class="text-[0.9375rem]"><span class="font-bold tabular-nums">{{ $reads['read'] }} de {{ $reads['total'] }}</span> {{ $reads['total'] === 1 ? 'persona lo leyó' : 'personas lo leyeron' }}</p>
            <p class="{{ $hint }}">Incluye a las personas de las empresas del anuncio que ya entraron al portal, sin cuentas de emergencia ni invitados. Quien tiene cuentas vinculadas en dos empresas cuenta como una persona y confirma una sola vez.</p>
            @if ($missing > 0)
                <details class="group">
                    {{-- list-none + its own chevron: inline-flex would hide the native marker. --}}
                    <summary class="inline-flex min-h-touch cursor-pointer list-none items-center gap-1.5 text-sm font-semibold text-fg-soft [&::-webkit-details-marker]:hidden">
                        <x-home.icon name="chevron-right" stroke-width="2" class="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" />Personas que faltan ({{ $missing }})
                    </summary>
                    <ul class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($reads['pending'] as $person)
                            <li class="break-words">{{ $person['name'] }}@if ($person['companies'] !== []) <span class="text-fg-muted">· {{ implode(', ', $person['companies']) }}</span>@endif</li>
                        @endforeach
                    </ul>
                    @if ($missing > count($reads['pending']))
                        <p class="{{ $hint }} mt-2">Y {{ $missing - count($reads['pending']) }} más.</p>
                    @endif
                </details>
            @else
                <p class="text-sm font-semibold text-status-active">Ya lo leyeron todos.</p>
            @endif
        </section>
    @endif
</div>
