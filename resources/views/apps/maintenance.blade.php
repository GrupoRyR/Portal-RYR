{{--
    Maintenance notice (docs/diseno/PhoneMaint.dc.html): shown instead of opening the app.
    Liquid Glass (odd/tasks/liquid-glass.md G9): one g-card in the .g-page
    column, the state as a warning g-pill.
--}}
<x-layouts.app :title="$app->name" noindex glass assistant>
    <x-portal-header />

    <main id="contenido" class="g-page max-w-[560px] md:mt-16">
        <section aria-labelledby="titulo-mantenimiento" class="g-card">
            <div class="g-card__body md:px-7 md:py-7">
                <div class="flex items-center gap-3.5">
                    <span aria-hidden="true" class="g-page__badge">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3l-2.5 2.5-2.5-.5-.5-2.5z"/></svg>
                    </span>
                    <div class="flex min-w-0 flex-col items-start gap-1.5">
                        <span class="g-pill warn">En mantenimiento</span>
                        <h1 id="titulo-mantenimiento" class="text-xl leading-tight font-bold break-words">{{ $app->name }}</h1>
                    </div>
                </div>

                <p class="g-page__text text-base">{{ $app->maintenance_message ?: 'Estamos haciendo ajustes en esta aplicación. Vuelve a intentarlo más tarde.' }}</p>

                @if ($app->maintenance_until)
                    <p class="g-page__callout">
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span>Vuelve a estar disponible: <strong>{{ $app->maintenance_until->locale('es')->translatedFormat('j \d\e F, g:i a') }}</strong></span>
                    </p>
                @endif

                <div class="g-page__actions g-page__actions--wide">
                    <a href="{{ route('home') }}" class="g-btn g-btn--primary px-5">Volver al inicio</a>
                    @if ($supportHref)
                        <a href="{{ $supportHref }}" target="_blank" rel="noopener" class="g-btn px-5">Avisar a Soporte TI</a>
                    @endif
                </div>
            </div>
        </section>
    </main>
</x-layouts.app>
