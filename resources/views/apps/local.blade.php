{{--
    Info page of an app installed on the computer (docs/diseno/PhoneLocal.dc.html).
    Liquid Glass (odd/tasks/liquid-glass.md G9): the app in the title banner
    with its state as a g-pill, the steps in a g-card, then the actions.
    The way home is the banner's back link.
--}}
@php($steps = collect(preg_split('/\R/u', (string) $app->local_instructions))->map(fn ($step) => trim($step))->filter()->values())
@php($downloadUrl = is_string($app->download_url) && str_starts_with(strtolower($app->download_url), 'https://') ? $app->download_url : null)
<x-layouts.app :title="$app->name" glass assistant>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <div class="g-page__app">
                @include('apps.partials.icon', ['app' => $app, 'class' => 'size-[72px]'])
                <div class="flex flex-col items-start gap-2">
                    <h1>{{ $app->name }}</h1>
                    <span class="g-pill inline-flex items-center gap-1.5">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                        Instalada en tu equipo
                    </span>
                </div>
                <p class="g-page__lead">Esta aplicación no se abre desde el navegador: se usa desde tu computador.</p>
            </div>
        </div>

        @if ($steps->isNotEmpty())
            <section aria-labelledby="como-abrirla" class="g-card">
                <div class="g-card__head">
                    <span class="g-mark" aria-hidden="true"></span>
                    <h2 id="como-abrirla">Cómo abrirla</h2>
                </div>
                <div class="g-card__body">
                    <ol class="g-page__steps">
                        @foreach ($steps as $step)
                            <li>
                                <span aria-hidden="true" class="g-page__step">{{ $loop->iteration }}</span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
        @endif

        <div class="g-page__actions g-page__actions--wide">
            @if ($downloadUrl)
                <a href="{{ $downloadUrl }}" rel="noopener" class="g-btn g-btn--primary px-5">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                    Descargar instalador
                </a>
            @endif
            @if ($supportHref)
                <a href="{{ $supportHref }}" target="_blank" rel="noopener" class="g-btn px-5">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/></svg>
                    Soporte TI
                </a>
            @endif
        </div>
    </main>
</x-layouts.app>
