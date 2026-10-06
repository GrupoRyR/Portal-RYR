{{-- Info page of an app installed on the computer (docs/diseno/PhoneLocal.dc.html). --}}
@php($steps = collect(preg_split('/\R/u', (string) $app->local_instructions))->map(fn ($step) => trim($step))->filter()->values())
@php($downloadUrl = is_string($app->download_url) && str_starts_with(strtolower($app->download_url), 'https://') ? $app->download_url : null)
<x-layouts.app :title="$app->name" class="bg-ground">
    <div class="mx-auto flex min-h-dvh w-full max-w-lg flex-col">
        <header class="flex shrink-0 items-center px-2 pt-4 pb-2">
            <a href="{{ route('home') }}" aria-label="Volver al inicio" class="flex size-touch items-center justify-center rounded-control hover:bg-subtle">
                <svg class="size-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>
            </a>
        </header>

        <main id="contenido" class="flex flex-1 flex-col gap-6 px-5 pb-6">
            <div class="flex flex-col items-start gap-3.5">
                @include('apps.partials.icon', ['app' => $app, 'class' => 'size-[72px]'])
                <div class="flex flex-col gap-1.5">
                    <h1 class="text-[1.875rem] leading-tight font-bold tracking-[-0.02em]">{{ $app->name }}</h1>
                    <span class="inline-flex items-center gap-1.5 self-start rounded-[8px] bg-status-local-bg px-2.5 py-1 text-[0.8125rem] font-semibold text-status-local">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                        Instalada en tu equipo
                    </span>
                </div>
                <p class="text-base leading-normal text-fg-soft">Esta aplicación no se abre desde el navegador: se usa desde tu computador.</p>
            </div>

            @if ($steps->isNotEmpty())
                <section aria-labelledby="como-abrirla" class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-[18px]">
                    <h2 id="como-abrirla" class="text-base font-bold">Cómo abrirla</h2>
                    <ol class="flex flex-col gap-3.5">
                        @foreach ($steps as $step)
                            <li class="flex items-start gap-3 text-[0.9375rem] leading-[1.45]">
                                <span aria-hidden="true" class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-fg text-[0.8125rem] font-bold text-on-fg">{{ $loop->iteration }}</span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            <div class="mt-auto flex flex-col gap-2.5">
                @if ($downloadUrl)
                    <a href="{{ $downloadUrl }}" rel="noopener" class="flex min-h-[52px] items-center justify-center gap-2.5 rounded-icon bg-brand-button text-base font-semibold text-white hover:opacity-90">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                        Descargar instalador
                    </a>
                @endif
                @if ($supportHref)
                    <a href="{{ $supportHref }}" target="_blank" rel="noopener" class="flex min-h-[52px] items-center justify-center gap-2.5 rounded-icon border border-line-strong bg-surface text-base font-semibold hover:bg-subtle">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M5.6 5.6l3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/></svg>
                        Soporte TI
                    </a>
                @endif
                <a href="{{ route('home') }}" class="flex min-h-[52px] items-center justify-center rounded-icon border border-line-strong bg-surface text-base font-semibold hover:bg-subtle">Volver al inicio</a>
            </div>
        </main>
    </div>
</x-layouts.app>
