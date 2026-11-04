{{--
    403 of the Claude connector's consent screen (App\Http\Middleware\
    EnsureAccountMayConnect): the signed-in account may not connect Claude
    (guest, emergency account, no Microsoft identity or a company without
    access). No code is issued. Liquid Glass (odd/tasks/liquid-glass.md
    G9): one g-card on the photo, no header and no assistant (a consent page).
--}}
<x-layouts.app title="No se puede conectar Claude" glass noindex>
    <main id="contenido" class="g-page min-h-[calc(100dvh-60px)] max-w-[560px] justify-center">
        <section class="g-card">
            <div class="g-card__body gap-5 md:px-8 md:py-8">
                <p class="g-page__code" aria-hidden="true">403</p>
                <div class="flex flex-col gap-3">
                    <h1 class="text-[1.75rem] leading-tight font-bold sm:text-[2rem]">Esta cuenta no puede conectar Claude.</h1>
                    <p class="g-page__text text-base">Solo las cuentas de Microsoft de las empresas con acceso al portal pueden conectar Claude. Las cuentas de invitado y la cuenta de emergencia no pueden.</p>
                    <p class="g-page__text text-base">Si crees que tu cuenta debería poder, pídelo al área de TI.</p>
                </div>
                <a href="{{ url('/') }}" class="g-btn g-btn--primary self-start px-5">Ir al inicio</a>
            </div>
        </section>
    </main>
</x-layouts.app>
