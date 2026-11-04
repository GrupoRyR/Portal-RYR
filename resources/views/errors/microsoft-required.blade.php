{{--
    403 for any action that needs a Microsoft sign-in (the emergency account
    and other local accounts): companies, tenants, linking and unlinking
    accounts. General on purpose; a Livewire action that refuses with its own
    message (e.g. RecentMicrosoftLogin::EMERGENCY_MESSAGE) keeps it.
--}}
<x-layouts.app title="Acceso denegado" noindex glass>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center px-4 py-12 sm:px-6">
        <section class="g-card flex flex-col gap-6 px-8 py-9 max-sm:px-6">
            <p class="font-display text-5xl font-bold text-(--g-ink-faint)" aria-hidden="true">403</p>
            <div class="flex flex-col gap-3">
                <h1 class="text-3xl font-bold text-balance sm:text-4xl">Esta acción requiere iniciar sesión con Microsoft.</h1>
                <p class="text-base leading-relaxed text-(--g-ink-soft)">Algunas acciones, como crear empresas, cambiar el inquilino de una empresa o vincular o desvincular cuentas, piden confirmar tu identidad con Microsoft. Entraste con una cuenta que no es de Microsoft, como la cuenta de emergencia, que sirve para entrar cuando Microsoft no está disponible.</p>
                <p class="text-base leading-relaxed text-(--g-ink-soft)">Para continuar, cierra la sesión y entra con tu cuenta de Microsoft.</p>
            </div>
            <a href="{{ url('/') }}" class="g-btn g-btn--primary w-fit px-5 font-semibold">Ir al inicio</a>
        </section>
    </main>
</x-layouts.app>
