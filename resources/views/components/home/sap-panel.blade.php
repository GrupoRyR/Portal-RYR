{{--
    «Panel gerencial» on «Bases de datos SAP» (odd/tasks/panel-gerencial-sap.md):
    the month's net sale, gross profit and margin (with its coverage notice,
    always shown), the receivables by range and the top sellers of the
    account's own company (App\View\Home\SapPanelCard). HomePageBuilder only
    builds it for a «Gerencia» account of that company. «Actualizar ventas y
    cartera» is a plain form (no JavaScript): the server asks Bases SAP with
    its own key, and the status and «Datos actualizados a las …» are read on
    the server (App\Management\SapRefresh). The ranges are a
    table with their values in text: the bars only repeat them for the eye.
    A glass card of the main column, like management-panel.blade.php, with
    the same id; data-offline-omit: the service worker (resources/js/sw.js)
    never saves it for offline use, so it holds no nested <section>.
--}}
@props(['card'])

{{-- The result of «Actualizar ventas y cartera» (App\Http\Controllers\Management\SapRefreshController).
     A successful press is left to the request's own status line when there is one, so it is not said twice. --}}
@php($flash = is_string($flashed = session(\App\Http\Controllers\Management\SapRefreshController::FLASH_KEY)) && $flashed !== '' && ($flashed !== \App\Management\SapRefreshOutcome::Requested->message() || $card->refreshStatus === null) ? $flashed : null)

<section id="panel-gerencial" aria-labelledby="panel-gerencial-titulo" data-offline-omit {{ $attributes->merge(['class' => 'g-card min-w-0 scroll-mt-6']) }}>
    <div class="g-card__head">
        <span class="g-mark" aria-hidden="true"></span>
        <h2 id="panel-gerencial-titulo">Panel gerencial</h2>
        <span class="g-note">{{ $card->period }}</span>
        <span class="g-pill ml-auto normal-case">Solo Gerencia</span>
    </div>

    <div class="g-card__body flex flex-col gap-5">
        @if (! $card->available)
            <p role="status" class="text-sm font-semibold text-fg-soft">{{ \App\View\Home\SapPanelCard::UNAVAILABLE }}</p>
        @else
            <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-3">
                <div class="flex min-w-0 flex-col gap-1.5">
                    <dt class="text-[0.8125rem] text-fg-muted">Venta neta del mes</dt>
                    <dd class="text-[2rem] font-bold leading-tight tracking-[-0.02em] tabular-nums break-words">{{ $card->netSales }}</dd>
                </div>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <dt class="text-[0.8125rem] text-fg-muted">Ganancia bruta</dt>
                    <dd class="text-2xl font-bold leading-tight tabular-nums break-words">{{ $card->grossProfit }}</dd>
                    <dd class="text-sm font-semibold text-fg-soft">Margen {{ $card->margin ?? 'sin dato' }}</dd>
                </div>
                <div class="flex min-w-0 flex-col gap-1.5">
                    <dt class="text-[0.8125rem] text-fg-muted">Cartera total</dt>
                    <dd class="text-2xl font-bold leading-tight tabular-nums break-words">{{ $card->receivables }}</dd>
                </div>
            </dl>

            <p class="text-xs text-fg-muted">{{ $card->coverageNotice }}</p>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <h3 id="panel-gerencial-rangos" class="text-[0.8125rem] font-semibold text-fg-soft">Cartera por rango</h3>
                    <div class="overflow-x-auto">
                        <table aria-labelledby="panel-gerencial-rangos" class="w-full text-sm tabular-nums">
                            <thead>
                                <tr class="text-left text-xs text-fg-muted">
                                    <th scope="col" class="py-1 pr-3 font-semibold">Rango</th>
                                    <th scope="col" class="py-1 pr-3 text-right font-semibold">Saldo</th>
                                    <th scope="col" class="py-1 font-semibold">Participación</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($card->ranges as $range)
                                    <tr class="border-t border-black/5 dark:border-white/10">
                                        <th scope="row" class="py-1.5 pr-3 text-left font-normal whitespace-nowrap">{{ $range['label'] }}</th>
                                        <td class="py-1.5 pr-3 text-right font-semibold whitespace-nowrap">{{ $range['value'] }}</td>
                                        <td class="py-1.5">
                                            <span class="flex items-center gap-2">
                                                <span class="g-bar w-16 shrink-0 sm:w-24" aria-hidden="true"><i style="width: {{ $range['share'] }}%"></i></span>
                                                <span class="text-xs text-fg-muted">{{ $range['share'] }} %</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($card->rangesNotice)
                        <p class="text-xs font-semibold text-fg-soft">{{ $card->rangesNotice }}</p>
                    @endif
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <h3 id="panel-gerencial-vendedores" class="text-[0.8125rem] font-semibold text-fg-soft">Top 5 vendedores del mes</h3>
                    @if ($card->sellers === [])
                        <p class="text-sm text-fg-muted">Sin ventas registradas en el mes.</p>
                    @else
                        <ol aria-labelledby="panel-gerencial-vendedores" class="flex list-decimal flex-col gap-1.5 pl-5 text-sm">
                            @foreach ($card->sellers as $seller)
                                <li class="pl-1">
                                    <span class="flex flex-wrap justify-between gap-x-3">
                                        <span class="min-w-0 break-words">{{ $seller['name'] }}</span>
                                        <span class="font-semibold tabular-nums">{{ $seller['value'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        @endif

        @if ($card->refreshAction || $card->refreshStatus || $card->updatedAt || $flash)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                @if ($card->refreshAction)
                    <form method="POST" action="{{ $card->refreshAction }}">
                        @csrf
                        <button type="submit" class="g-btn">Actualizar ventas y cartera</button>
                    </form>
                @endif
                <div class="flex min-w-0 flex-col gap-0.5 text-xs">
                    @if ($flash)
                        <p role="status" data-flash class="font-semibold text-fg-soft">{{ $flash }}</p>
                    @endif
                    @if ($card->refreshStatus)
                        <p role="status" class="font-semibold text-fg-soft">{{ $card->refreshStatus }}</p>
                    @endif
                    @if ($card->updatedAt)
                        <p class="text-fg-muted">{{ $card->updatedAt }}</p>
                    @endif
                </div>
            </div>
        @endif

        <p class="text-xs text-fg-muted">{{ $card->source }}</p>
    </div>
</section>
