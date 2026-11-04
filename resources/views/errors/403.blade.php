{{-- Shown for every 403, whatever the exception message says. --}}
<x-layouts.app title="Acceso denegado" noindex glass>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center px-4 py-12 sm:px-6">
        <section class="g-card flex flex-col gap-6 px-8 py-9 max-sm:px-6">
            <p class="font-display text-5xl font-bold text-(--g-ink-faint)" aria-hidden="true">403</p>
            <div class="flex flex-col gap-3">
                <h1 class="text-3xl font-bold text-balance sm:text-4xl">No tienes permiso para ver esta página.</h1>
                <p class="text-base leading-relaxed text-(--g-ink-soft)">Si necesitas este acceso, pídelo al área de TI indicando la página que intentabas abrir.</p>
            </div>
            <a href="{{ url('/') }}" class="g-btn g-btn--primary w-fit px-5 font-semibold">Ir al inicio</a>
        </section>
    </main>
</x-layouts.app>
