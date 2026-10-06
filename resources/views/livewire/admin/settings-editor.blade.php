{{-- Configuración (docs/diseno/AdminSettings.dc.html): portal-wide settings, saved together. --}}
@php
    $field = 'h-11 w-full min-w-0 rounded-[10px] border border-line-strong bg-surface px-3 text-[0.9375rem] text-fg placeholder:text-fg-muted';
    $label = 'text-[0.8125rem] font-semibold';
    $hint = 'text-[0.8125rem] text-fg-muted';
    $error = 'text-[0.8125rem] font-semibold text-status-failure';
    $card = 'flex min-w-0 flex-col gap-3.5 rounded-[18px] border border-line bg-surface p-5 sm:p-[22px]';
    $heading = 'font-sans text-lg font-bold tracking-normal';
@endphp
<form wire:submit="save" class="flex flex-col gap-5" novalidate>
    <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="text-[1.75rem] leading-tight font-bold sm:text-[2rem]">Configuración</h1>
            <p class="text-[0.9375rem] text-fg-muted">Ajustes que aplican a todo el portal y a todas las empresas.</p>
        </div>
        <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex h-11 shrink-0 items-center rounded-control bg-brand-button px-5 text-[0.9375rem] font-semibold text-white disabled:opacity-60">
            Guardar cambios
        </button>
    </header>

    <p role="status" @class(['rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active', 'sr-only' => $notice === ''])>{{ $notice }}</p>

    @if (collect($errors->keys())->diff(['backup'])->isNotEmpty())
        <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">Revisa los campos marcados. No se guardó ningún cambio.</p>
    @endif

    <div class="grid grid-cols-1 items-start gap-5 min-[1100px]:grid-cols-2">
        {{-- Soporte TI --}}
        <section aria-labelledby="s-support" class="{{ $card }}">
            <h2 id="s-support" class="{{ $heading }}">Soporte TI</h2>
            <div class="flex flex-col gap-1.5">
                <label for="s-url" class="{{ $label }}">Dirección de la plataforma de tickets</label>
                <input id="s-url" type="url" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://"
                       maxlength="{{ \App\Settings\PortalSettings::SUPPORT_URL_MAX }}"
                       wire:model.live.debounce.400ms="supportUrl"
                       aria-describedby="s-url-h @error('supportUrl') s-url-error @enderror"
                       @error('supportUrl') aria-invalid="true" @enderror
                       class="{{ $field }} font-mono text-sm">
                <span id="s-url-h" class="{{ $hint }}">Si queda vacía, el botón «Soporte TI» no aparece en el portal.</span>
                @error('supportUrl') <p id="s-url-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-1.5 rounded-control bg-ground px-3.5 py-3">
                <span class="text-[0.8125rem] font-semibold">Así llega el ticket</span>
                <code class="font-mono text-[0.8125rem] leading-normal break-all text-fg-soft">{{ $ticketExample }}</code>
                <span class="text-xs text-fg-muted">«app» es la última que abrió la persona en los últimos 30 minutos; si no abrió ninguna, no se envía. Nunca se envían datos personales.</span>
            </div>
        </section>

        {{-- Sesión --}}
        <section aria-labelledby="s-session" class="{{ $card }}">
            <h2 id="s-session" class="{{ $heading }}">Sesión</h2>
            <div class="flex flex-col gap-1.5">
                <label for="s-exp" class="{{ $label }}">Cerrar la sesión por inactividad después de</label>
                <select id="s-exp" wire:model="idleTimeout" class="{{ $field }} px-2.5"
                        @error('idleTimeout') aria-invalid="true" aria-describedby="s-exp-error" @enderror>
                    @foreach ($idleOptions as $minutes => $text)
                        <option value="{{ $minutes }}" @selected($minutes === $idleTimeout)>{{ $text }}</option>
                    @endforeach
                </select>
                @error('idleTimeout') <p id="s-exp-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <label class="flex min-h-touch cursor-pointer items-center justify-between gap-4">
                <span class="flex flex-col gap-0.5">
                    <span class="text-[0.9375rem] font-semibold">Al salir, cerrar también Microsoft</span>
                    <span id="s-ms-h" class="{{ $hint }}">Si está apagado, solo se cierra el portal.</span>
                </span>
                <input type="checkbox" role="switch" wire:model="microsoftLogout" aria-describedby="s-ms-h"
                       class="relative h-[26px] w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-line-strong transition-colors before:absolute before:top-[3px] before:left-[3px] before:size-5 before:rounded-full before:bg-white before:transition-[left] checked:bg-status-active-dot checked:before:left-[21px]">
            </label>
        </section>

        {{-- Dominios permitidos --}}
        <section aria-labelledby="s-hosts" class="{{ $card }}">
            <h2 id="s-hosts" class="{{ $heading }}">Dominios permitidos para apps</h2>
            <p class="text-sm text-fg-soft">Solo se aceptan direcciones https:// en estos dominios. Un dominio incluye sus subdominios; <code class="font-mono text-[0.8125rem]">*</code> reemplaza parte de una sola etiqueta, como en <code class="font-mono text-[0.8125rem]">sgx-*.vercel.app</code>.</p>
            <ul class="flex flex-wrap gap-2" role="list" aria-label="Dominios permitidos">
                @foreach ($hosts as $host)
                    <li wire:key="host-{{ $host }}" class="inline-flex h-9 max-w-full items-center gap-1 rounded-[10px] bg-subtle pr-1 pl-3 font-mono text-[0.8125rem]">
                        <span class="truncate">{{ $host }}</span>
                        <button type="button" wire:click="removeHost(@js($host))"
                                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-line hover:text-status-failure">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            <span class="sr-only">Quitar {{ $host }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
            @error('hosts') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
            <div class="flex flex-col gap-1.5">
                <label for="s-host" class="sr-only">Nuevo dominio</label>
                <div class="flex gap-2">
                    <input id="s-host" type="text" autocomplete="off" spellcheck="false" autocapitalize="none" maxlength="253" placeholder="dominio.com.co"
                           wire:model="newHost" wire:keydown.enter.prevent="addHost"
                           @error('newHost') aria-invalid="true" aria-describedby="s-host-error" @enderror
                           class="{{ $field }} h-9 flex-1 font-mono text-sm">
                    <button type="button" wire:click="addHost"
                            class="inline-flex h-9 shrink-0 items-center rounded-[10px] border border-dashed border-line-strong px-3 text-[0.8125rem] font-semibold text-fg-soft hover:bg-subtle hover:text-fg">
                        + Agregar
                    </button>
                </div>
                @error('newHost') <p id="s-host-error" class="{{ $error }}">{{ $message }}</p> @enderror
                <p class="text-xs text-fg-muted">Los cambios de la lista se aplican al guardar.</p>
            </div>
        </section>

        {{-- Estados reportados por la API --}}
        <section aria-labelledby="s-api" class="{{ $card }}">
            <h2 id="s-api" class="{{ $heading }}">Estados reportados por la API</h2>
            <div class="flex flex-col gap-1.5">
                <label for="s-ttl" class="{{ $label }}">Si no llegan reportes, quitar «Con fallas» después de</label>
                <select id="s-ttl" wire:model="apiStatusTtl" aria-describedby="s-ttl-h" class="{{ $field }} px-2.5"
                        @error('apiStatusTtl') aria-invalid="true" @enderror>
                    @foreach (\App\Settings\PortalSettings::API_STATUS_TTL_CHOICES as $hours)
                        <option value="{{ $hours }}" @selected($hours === $apiStatusTtl)>{{ $hours === 1 ? '1 hora' : "{$hours} horas" }}</option>
                    @endforeach
                </select>
                <span id="s-ttl-h" class="{{ $hint }}">Si un sistema deja de reportar una app con fallas, la tarjeta vuelve a verse normal después de este tiempo.</span>
                @error('apiStatusTtl') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- Respaldo diario: real data from the backups disk and the recorded runs (App\Backups\BackupOverview).
             «Respaldar ahora» acts at once (no «Guardar cambios»): it leaves a request for the scheduler. --}}
        <section aria-labelledby="s-backup" class="{{ $card }} flex-row! flex-wrap items-center"
                 @if ($backup->isBusy()) wire:poll.15s @endif>
            <svg class="size-[22px] shrink-0 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                <h2 id="s-backup" class="font-sans text-[0.9375rem] font-bold tracking-normal">Respaldo diario de la base de datos</h2>
                <span class="{{ $hint }}">{{ $backup->lastBackupLine() }}</span>
                @if ($copies = $backup->copiesLine())
                    <span class="text-xs text-fg-muted">{{ $copies }}</span>
                @endif
                @if ($failure = $backup->failureLine())
                    <span class="{{ $error }}">{{ $failure }} Se avisó por correo a los súper administradores.</span>
                @endif
                <span role="status" @class(['text-[0.8125rem] font-semibold text-fg-soft', 'sr-only' => ! $backup->isBusy()])>{{ $backup->progressLine() }}</span>
                @error('backup') <span role="alert" class="{{ $error }}">{{ $message }}</span> @enderror
            </span>
            <button type="button" wire:click="requestBackup" wire:loading.attr="disabled" wire:target="requestBackup" @disabled($backup->isBusy())
                    class="inline-flex h-10 shrink-0 items-center rounded-[10px] border border-line-strong bg-surface px-3.5 text-sm font-semibold hover:bg-subtle disabled:cursor-not-allowed disabled:opacity-60">
                {{ $backup->isBusy() ? 'Programado…' : 'Respaldar ahora' }}
            </button>
        </section>
    </div>
</form>
