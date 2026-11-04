{{--
    Confirmation before unlinking one of the person's accounts
    (App\Http\Controllers\Account\AccountController::confirmUnlink), shown
    after a fresh Microsoft confirmation. The POST unlinks; no JavaScript.
    Liquid Glass (odd/tasks/liquid-glass.md G9): a title banner and a card.
--}}
@use('App\Models\Company')
@php($company = Company::displayName($account->company))
<x-layouts.app title="Desvincular cuenta" glass assistant noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('account.show') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Cuentas vinculadas
            </a>
            <h1>¿Desvincular la cuenta de {{ $company }}?</h1>
        </div>

        <section aria-labelledby="la-cuenta" class="g-card">
            <div class="g-card__head">
                <span class="g-mark" aria-hidden="true"></span>
                <h2 id="la-cuenta">{{ $company }}</h2>
            </div>
            <div class="g-card__body">
                <p class="g-page__fine -mt-1 break-all">{{ $account->email }}</p>
                <ul role="list" class="g-page__bullets">
                    @if ($account->is($current))
                        <li>Es la cuenta con la que estás ahora. Sigues en ella, pero ya no podrás cambiar a tus otras cuentas desde el encabezado.</li>
                    @else
                        <li>Ya no podrás cambiar a esta cuenta desde el encabezado.</li>
                    @endif
                    @if ($lastLink)
                        <li>Es tu único vínculo: tus dos cuentas quedarán separadas.</li>
                    @endif
                    <li>La cuenta no se borra y conserva sus permisos, aplicaciones y favoritos.</li>
                    <li>Te avisaremos por correo en cada una de tus cuentas.</li>
                    <li>Puedes volver a vincularla cuando quieras.</li>
                </ul>

                <div class="g-page__actions">
                    <form method="POST" action="{{ route('account.unlink', $account) }}">
                        @csrf
                        <button type="submit" class="g-btn g-btn--danger px-5">
                            Desvincular<span class="sr-only"> la cuenta de {{ $company }}</span>
                        </button>
                    </form>
                    <a href="{{ route('account.show') }}" class="g-btn px-5">Cancelar</a>
                </div>
            </div>
        </section>
    </main>
</x-layouts.app>
