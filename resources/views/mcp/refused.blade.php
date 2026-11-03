{{--
    403 of the Claude connector's consent screen (App\Http\Middleware\
    EnsureAccountMayConnect): the signed-in account may not connect Claude
    (guest, emergency account, no Microsoft identity or a company without
    access). No code is issued.
--}}
<x-layouts.app title="No se puede conectar Claude" noindex>
    <main class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center gap-6 px-4 py-12 sm:px-6">
        <p class="font-display text-5xl font-bold text-fg-muted" aria-hidden="true">403</p>
        <div class="flex flex-col gap-3">
            <h1 class="text-3xl font-bold sm:text-4xl">Esta cuenta no puede conectar Claude.</h1>
            <p class="text-base leading-relaxed text-fg-soft">Solo las cuentas de Microsoft de las empresas del grupo con acceso al portal pueden conectar Claude. Las cuentas de invitado y la cuenta de emergencia no pueden.</p>
            <p class="text-base leading-relaxed text-fg-soft">Si crees que tu cuenta debería poder, pídelo al área de TI.</p>
        </div>
        <a href="{{ url('/') }}" class="inline-flex min-h-touch w-fit items-center rounded-control bg-brand-button px-5 font-semibold text-white">Ir al inicio</a>
    </main>
</x-layouts.app>
