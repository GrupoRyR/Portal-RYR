{{-- 503 when a re-authentication cannot be checked (no auth_time claim). The session stays. --}}
<x-layouts.app title="No pudimos confirmar tu identidad" noindex>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center gap-6 px-4 py-12 sm:px-6">
        <div class="flex flex-col gap-3">
            <h1 class="text-3xl font-bold sm:text-4xl">{{ \App\Auth\RecentMicrosoftLogin::UNAVAILABLE_MESSAGE }}</h1>
            <p class="text-base leading-relaxed text-fg-soft">Falta una configuración del portal en Microsoft, así que esta acción no se puede hacer por ahora. Tu sesión sigue abierta.</p>
            <p class="text-sm leading-relaxed text-fg-muted">Para TI: agrega el dato opcional <code>auth_time</code> al token de identificación (ID) del registro de la app en Microsoft Entra.</p>
        </div>
        <a href="{{ url('/') }}" class="inline-flex min-h-touch w-fit items-center rounded-control bg-brand-button px-5 font-semibold text-white">Ir al inicio</a>
    </main>
</x-layouts.app>
