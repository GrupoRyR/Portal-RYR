{{--
    «Vincular otra cuenta» (App\Http\Controllers\Account\LinkedAccountController):
    explains the link and sends the person to Microsoft to sign in to the
    other account. Behind microsoft.recent; no Livewire or Alpine. Liquid
    Glass (odd/tasks/liquid-glass.md G9): a title banner and glass cards.
--}}
@use('App\Models\Company')
<x-layouts.app title="Vincular otra cuenta" glass assistant noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1>Vincular otra cuenta</h1>
            <p class="g-page__lead">Si también tienes una cuenta de Microsoft en otra empresa, vincúlala con esta para tener tus cuentas juntas.</p>
        </div>

        <section aria-labelledby="como-funciona" class="g-card">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="como-funciona">Cómo funciona</h2>
            </div>
            <div class="g-card__body">
                <ul role="list" class="g-page__bullets">
                    <li>Microsoft te pedirá elegir la otra cuenta e iniciar sesión con ella.</li>
                    <li>Tu sesión actual no cambia: sigues en {{ Company::displayName($company) }}.</li>
                    <li>Cada cuenta conserva sus propios permisos, aplicaciones y favoritos.</li>
                    <li>Te avisaremos por correo en cada una de tus cuentas.</li>
                    <li>Solo puedes vincular una cuenta por empresa. Las cuentas de invitado no se pueden vincular.</li>
                </ul>

                <form method="POST" action="{{ route('account.link.start') }}">
                    @csrf
                    <button type="submit" class="g-btn g-btn--primary gap-3 px-5">
                        {{-- Microsoft's four squares, in the button's own color. --}}
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8.5" height="8.5"/><rect x="12.5" y="3" width="8.5" height="8.5"/><rect x="3" y="12.5" width="8.5" height="8.5"/><rect x="12.5" y="12.5" width="8.5" height="8.5"/></svg>
                        Continuar con Microsoft
                    </button>
                </form>
            </div>
        </section>

        @if ($linked->isNotEmpty())
            <section aria-labelledby="vinculadas" class="g-card">
                <div class="g-card__head">
                    <span class="g-mark" aria-hidden="true"></span>
                    <h2 id="vinculadas">Cuentas vinculadas</h2>
                </div>
                <div class="g-card__body">
                    <ul role="list" class="g-page__rows">
                        @foreach ($linked as $account)
                            <li class="g-page__row">
                                <span class="g-page__who">
                                    <span>{{ Company::displayName($account->company) }}</span>
                                    <span>{{ $account->email }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif
    </main>
</x-layouts.app>
