{{--
    Confirmation before unlinking one of the person's accounts
    (App\Http\Controllers\Account\AccountController::confirmUnlink), shown
    after a fresh Microsoft confirmation. The POST unlinks; no JavaScript.
--}}
@use('App\Models\Company')
@php($company = Company::displayName($account->company))
<x-layouts.app title="Desvincular cuenta" brand-band noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('account.show') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Cuentas vinculadas
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">¿Desvincular la cuenta de {{ $company }}?</h1>
        </div>

        <section class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6">
            <div class="flex flex-col">
                <span class="font-semibold">{{ $company }}</span>
                <span class="truncate text-[0.8125rem] text-fg-muted">{{ $account->email }}</span>
            </div>

            <ul role="list" class="flex flex-col gap-2.5 text-[0.9375rem] leading-relaxed text-fg-soft">
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

            <div class="flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('account.unlink', $account) }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-touch cursor-pointer items-center rounded-control border border-status-failure-line px-5 font-semibold text-status-failure hover:bg-status-failure-bg">
                        Desvincular<span class="sr-only"> la cuenta de {{ $company }}</span>
                    </button>
                </form>
                <a href="{{ route('account.show') }}" class="inline-flex min-h-touch items-center px-3 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">Cancelar</a>
            </div>
        </section>
    </main>
</x-layouts.app>
