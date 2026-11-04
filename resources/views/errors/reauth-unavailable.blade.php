{{-- 503 when a re-authentication cannot be checked (no auth_time claim). The session stays. --}}
<x-layouts.app title="No pudimos confirmar tu identidad" noindex glass>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center px-4 py-12 sm:px-6">
        <section class="g-card flex flex-col gap-6 px-8 py-9 max-sm:px-6">
            <div class="flex flex-col gap-3">
                <h1 class="text-3xl font-bold text-balance sm:text-4xl">{{ \App\Auth\RecentMicrosoftLogin::UNAVAILABLE_MESSAGE }}</h1>
                <p class="text-base leading-relaxed text-(--g-ink-soft)">Falta una configuración del portal en Microsoft, así que esta acción no se puede hacer por ahora. Tu sesión sigue abierta.</p>
                <p class="text-sm leading-relaxed text-(--g-ink-faint)">Para TI: agrega el dato opcional <code>auth_time</code> al token de identificación (ID) del registro de la app en Microsoft Entra.</p>
            </div>
            <a href="{{ url('/') }}" class="g-btn g-btn--primary w-fit px-5 font-semibold">Ir al inicio</a>
        </section>
    </main>
</x-layouts.app>
