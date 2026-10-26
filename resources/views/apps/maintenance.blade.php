{{-- Maintenance notice (docs/diseno/PhoneMaint.dc.html): shown instead of opening the app. --}}
<x-layouts.app :title="$app->name" noindex class="flex flex-col bg-ground">
    <x-brand-bar />

    <main id="contenido" class="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-4 py-10 sm:px-6">
        <section aria-labelledby="titulo-mantenimiento" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 sm:p-6">
            <div class="flex items-center gap-3.5">
                <span aria-hidden="true" class="flex size-14 shrink-0 items-center justify-center rounded-[16px] bg-status-maintenance-bg text-status-maintenance">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3l-2.5 2.5-2.5-.5-.5-2.5z"/></svg>
                </span>
                <div class="flex flex-col gap-1">
                    <span class="self-start rounded-[6px] bg-status-maintenance-bg px-2 py-0.5 text-xs font-bold text-status-maintenance">En mantenimiento</span>
                    <h1 id="titulo-mantenimiento" class="text-xl font-bold">{{ $app->name }}</h1>
                </div>
            </div>

            <p class="text-base leading-normal text-fg-soft">{{ $app->maintenance_message ?: 'Estamos haciendo ajustes en esta aplicación. Vuelve a intentarlo más tarde.' }}</p>

            @if ($app->maintenance_until)
                <p class="flex items-center gap-2.5 rounded-icon bg-ground px-3.5 py-3 text-[0.9375rem]">
                    <svg class="size-5 shrink-0 text-fg-soft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span>Vuelve a estar disponible: <strong>{{ $app->maintenance_until->locale('es')->translatedFormat('j \d\e F, g:i a') }}</strong></span>
                </p>
            @endif

            <div class="mt-1 flex flex-col gap-2.5">
                <a href="{{ route('home') }}" class="flex min-h-[52px] items-center justify-center rounded-icon bg-brand-button text-base font-semibold text-white hover:opacity-90">Volver al inicio</a>
                @if ($supportHref)
                    <a href="{{ $supportHref }}" target="_blank" rel="noopener" class="flex min-h-[52px] items-center justify-center rounded-icon border border-line-strong bg-surface text-base font-semibold hover:bg-subtle">Avisar a Soporte TI</a>
                @endif
            </div>
        </section>
    </main>
</x-layouts.app>
