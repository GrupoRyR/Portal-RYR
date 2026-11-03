{{--
    403 for any action that needs a Microsoft sign-in (the emergency account
    and other local accounts): companies, tenants, linking and unlinking
    accounts. General on purpose; a Livewire action that refuses with its own
    message (e.g. RecentMicrosoftLogin::EMERGENCY_MESSAGE) keeps it.
--}}
<x-layouts.app title="Acceso denegado" noindex>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center gap-6 px-4 py-12 sm:px-6">
        <p class="font-display text-5xl font-bold text-fg-muted" aria-hidden="true">403</p>
        <div class="flex flex-col gap-3">
            <h1 class="text-3xl font-bold sm:text-4xl">Esta acción requiere iniciar sesión con Microsoft.</h1>
            <p class="text-base leading-relaxed text-fg-soft">Algunas acciones, como crear empresas, cambiar el inquilino de una empresa o vincular o desvincular cuentas, piden confirmar tu identidad con Microsoft. Entraste con una cuenta que no es de Microsoft, como la cuenta de emergencia, que sirve para entrar cuando Microsoft no está disponible.</p>
            <p class="text-base leading-relaxed text-fg-soft">Para continuar, cierra la sesión y entra con tu cuenta de Microsoft.</p>
        </div>
        <a href="{{ url('/') }}" class="inline-flex min-h-touch w-fit items-center rounded-control bg-brand-button px-5 font-semibold text-white">Ir al inicio</a>
    </main>
</x-layouts.app>
