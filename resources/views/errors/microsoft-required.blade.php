{{-- 403 for actions that need a Microsoft sign-in (emergency accounts). --}}
<x-layouts.app title="Acceso denegado" noindex>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center gap-6 px-4 py-12 sm:px-6">
        <p class="font-display text-5xl font-bold text-fg-muted" aria-hidden="true">403</p>
        <div class="flex flex-col gap-3">
            <h1 class="text-3xl font-bold sm:text-4xl">{{ \App\Auth\RecentMicrosoftLogin::EMERGENCY_MESSAGE }}</h1>
            <p class="text-base leading-relaxed text-fg-soft">La cuenta de emergencia sirve para entrar cuando Microsoft no está disponible, pero no puede agregar organizaciones de Microsoft al portal.</p>
        </div>
        <a href="{{ url('/') }}" class="inline-flex min-h-touch w-fit items-center rounded-control bg-brand-button px-5 font-semibold text-white">Ir al inicio</a>
    </main>
</x-layouts.app>
