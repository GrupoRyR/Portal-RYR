{{--
    «Vincular otra cuenta» (App\Http\Controllers\Account\LinkedAccountController):
    explains the link and sends the person to Microsoft to sign in to the
    other account. Behind microsoft.recent; no Livewire or Alpine.
--}}
@use('App\Models\Company')
<x-layouts.app title="Vincular otra cuenta" brand-band noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Vincular otra cuenta</h1>
        </div>

        <section class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6">
            <p class="text-base leading-relaxed">Si también tienes una cuenta de Microsoft en otra empresa del grupo, vincúlala con esta para tener tus cuentas juntas.</p>

            <ul role="list" class="flex flex-col gap-2.5 text-[0.9375rem] leading-relaxed text-fg-soft">
                <li>Microsoft te pedirá elegir la otra cuenta e iniciar sesión con ella.</li>
                <li>Tu sesión actual no cambia: sigues en {{ Company::displayName($company) }}.</li>
                <li>Cada cuenta conserva sus propios permisos, aplicaciones y favoritos.</li>
                <li>Te avisaremos por correo en cada una de tus cuentas.</li>
                <li>Solo puedes vincular una cuenta por empresa. Las cuentas de invitado no se pueden vincular.</li>
            </ul>

            <form method="POST" action="{{ route('account.link.start') }}">
                @csrf
                <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center gap-3 rounded-control bg-brand-button px-5 font-semibold text-white">
                    {{-- Microsoft's four squares, in the button's own color. --}}
                    <svg class="size-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8.5" height="8.5"/><rect x="12.5" y="3" width="8.5" height="8.5"/><rect x="3" y="12.5" width="8.5" height="8.5"/><rect x="12.5" y="12.5" width="8.5" height="8.5"/></svg>
                    Continuar con Microsoft
                </button>
            </form>
        </section>

        @if ($linked->isNotEmpty())
            <section aria-labelledby="vinculadas" class="flex flex-col gap-3">
                <h2 id="vinculadas" class="text-lg font-bold">Cuentas vinculadas</h2>
                <ul role="list" class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface">
                    @foreach ($linked as $account)
                        <li class="flex flex-col px-5 py-3">
                            <span class="font-semibold">{{ Company::displayName($account->company) }}</span>
                            <span class="truncate text-[0.8125rem] text-fg-muted">{{ $account->email }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
</x-layouts.app>
