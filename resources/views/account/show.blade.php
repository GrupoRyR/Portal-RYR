{{--
    «Cuentas vinculadas» (App\Http\Controllers\Account\AccountController::show):
    the accounts of the signed-in user's person, each with «Desvincular»
    (a confirmation page first), and «Vincular otra empresa». No Livewire or
    Alpine. $accounts is empty when the account is not linked.
--}}
@use('App\Models\Company')
<x-layouts.app title="Cuentas vinculadas" brand-band noindex>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Cuentas vinculadas</h1>
        </div>

        @if (is_string($status = session('status')) && $status !== '')
            <p role="status" data-flash class="rounded-control border border-line bg-surface px-4 py-3 text-sm font-semibold">{{ $status }}</p>
        @endif

        @if ($accounts->isEmpty())
            <section class="flex flex-col gap-5 rounded-card border border-line bg-surface p-5 md:p-6">
                <div class="flex flex-col gap-2">
                    <p class="text-base leading-relaxed font-semibold">Aún no tienes cuentas vinculadas.</p>
                    <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Si también tienes una cuenta de Microsoft en otra empresa del grupo, vincúlala con esta para cambiar de empresa desde el encabezado, sin cerrar la sesión.</p>
                </div>
                <a href="{{ route('account.link') }}" class="inline-flex min-h-touch items-center gap-2 self-start rounded-control bg-brand-button px-5 font-semibold text-white">
                    <x-home.icon name="link" stroke-width="2" class="size-[18px]" />Vincular otra empresa
                </a>
            </section>
        @else
            <section aria-labelledby="tus-cuentas" class="flex flex-col gap-3">
                <h2 id="tus-cuentas" class="sr-only">Tus cuentas</h2>
                <p class="text-[0.9375rem] leading-relaxed text-fg-soft">Puedes cambiar entre estas cuentas desde el encabezado. Cada una conserva sus propios permisos, aplicaciones y favoritos.</p>
                <ul role="list" class="flex flex-col divide-y divide-line rounded-card border border-line bg-surface">
                    @foreach ($accounts as $account)
                        @php($isCurrent = $account->is($current))
                        <li data-linked-account @if ($isCurrent) aria-current="true" @endif class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
                            <span class="flex min-w-0 flex-col">
                                <span class="font-semibold">{{ Company::displayName($account->company) }}</span>
                                <span class="truncate text-[0.8125rem] text-fg-muted">{{ $account->email }}</span>
                                @if ($isCurrent)
                                    <span class="text-[0.8125rem] font-semibold text-brand-ink">Esta cuenta</span>
                                @endif
                            </span>
                            <a href="{{ route('account.unlink.confirm', $account) }}" class="inline-flex min-h-touch items-center text-sm font-semibold text-status-failure underline-offset-4 hover:underline">
                                Desvincular<span class="sr-only"> la cuenta de {{ Company::displayName($account->company) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('account.link') }}" class="inline-flex min-h-touch items-center gap-2 self-start rounded-control bg-brand-button px-5 font-semibold text-white">
                    <x-home.icon name="link" stroke-width="2" class="size-[18px]" />Vincular otra empresa
                </a>
            </section>
        @endif

        <p class="text-[0.8125rem] leading-relaxed text-fg-muted">Solo puedes vincular una cuenta por empresa, y solo de empresas activas en el portal. Las cuentas de invitado y la cuenta de emergencia no se pueden vincular.</p>
    </main>
</x-layouts.app>
