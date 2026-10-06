{{--
    Informe de uso (docs/diseno/AdminUsage.dc.html), from real rows only
    (App\Usage\UsageReport). Charts follow the dataviz rules: one series per
    chart, thin marks with 4px rounded data ends, values in text tokens,
    a 2px surface gap between meter segments and a table view of the bars.
--}}
@php
    use App\Usage\UsageReport;

    $number = fn (int $n) => number_format($n, 0, ',', '.');
    $launches = fn (int $n) => $n === 1 ? '1 apertura' : $number($n).' aperturas';
    $devices = [
        'desktop' => ['label' => 'Escritorio', 'color' => 'bg-viz-1'],
        'tablet' => ['label' => 'Tablet', 'color' => 'bg-viz-2'],
        'phone' => ['label' => 'Celular', 'color' => 'bg-viz-3'],
    ];
    $percent = $report->devicePercentages;
    $tile = 'flex flex-col gap-1 rounded-[16px] border border-line bg-surface px-5 py-[18px]';
    $tileValue = 'font-sans text-[2.5rem] leading-[1.1] font-semibold tracking-tight';
    $days = $report->days.' días';
@endphp
<x-layouts.admin title="Informe de uso">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Informe de uso</h1>
            <p class="text-[0.9375rem] text-fg-muted">Qué apps se usan, quién entra y qué se puede retirar.</p>
        </div>
        <nav aria-label="Periodo">
            <ul class="flex gap-1 rounded-[10px] bg-subtle p-[3px]">
                @foreach (UsageReport::PERIODS as $period)
                    @php($current = $report->days === $period)
                    <li>
                        <a href="{{ route('admin.usage.index', ['periodo' => $period]) }}"@if ($current) aria-current="true"@endif
                           @class([
                               'inline-flex h-[38px] items-center rounded-lg px-3 text-[0.8125rem] whitespace-nowrap',
                               'bg-surface font-bold shadow-sm' => $current,
                               'font-semibold text-fg-soft hover:text-fg' => ! $current,
                           ])>{{ $period }} días</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
        @foreach ($report->activeUsers as $company => $count)
            <div class="{{ $tile }}">
                <span class="text-sm text-fg-muted">Usuarios activos · {{ $company }}</span>
                <span class="{{ $tileValue }}">{{ $number($count) }}</span>
                <span class="text-[0.8125rem] text-fg-soft">entraron al portal o abrieron una app al menos una vez en {{ $days }}</span>
            </div>
        @endforeach

        <div class="{{ $tile }}">
            <span class="text-sm text-fg-muted">Aperturas de apps</span>
            <span class="{{ $tileValue }}">{{ $number($report->totalLaunches) }}</span>
            <span class="text-[0.8125rem] text-fg-soft">desde el portal en {{ $days }}</span>
        </div>

        <div class="{{ $tile }} gap-2.5">
            <span class="text-sm text-fg-muted">Desde dónde abren las apps</span>
            @if ($percent === [])
                <span class="text-[0.8125rem] text-fg-soft">Nadie abrió apps desde el portal en este periodo.</span>
            @else
                <span class="flex items-baseline gap-2">
                    <span class="{{ $tileValue }}">{{ $percent['desktop'] }} %</span>
                    <span class="text-sm text-fg-soft">escritorio</span>
                </span>
                <div role="img"
                     aria-label="Escritorio {{ $percent['desktop'] }} %, tablet {{ $percent['tablet'] }} %, celular {{ $percent['phone'] }} %"
                     class="flex h-2.5 gap-[2px] overflow-hidden rounded-[4px]">
                    @foreach ($devices as $device => $style)
                        @if ($report->deviceCounts[$device] > 0)
                            <span class="h-full min-w-[2px] {{ $style['color'] }}" style="width: {{ $percent[$device] }}%"
                                  title="{{ $style['label'] }}: {{ $launches($report->deviceCounts[$device]) }} ({{ $percent[$device] }} %)"></span>
                        @endif
                    @endforeach
                </div>
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-[0.8125rem] text-fg-soft">
                    @foreach ($devices as $device => $style)
                        <li class="flex items-center gap-1.5">
                            <span class="size-2 rounded-full {{ $style['color'] }}" aria-hidden="true"></span>
                            {{ $style['label'] }} <span class="font-semibold text-fg">{{ $percent[$device] }} %</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">
        <section aria-labelledby="usage-top-title" x-data="{ table: false }"
                 class="flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface px-5 py-5 sm:px-[22px]">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="usage-top-title" class="font-sans text-lg font-bold tracking-normal">Apps más abiertas</h2>
                @if ($report->topApps !== [])
                    <button type="button" x-on:click="table = ! table" aria-pressed="false" x-bind:aria-pressed="table.toString()"
                            aria-controls="usage-bars usage-table"
                            class="inline-flex min-h-9 items-center rounded-lg px-2.5 text-[0.8125rem] font-semibold text-fg-soft hover:bg-subtle hover:text-fg">
                        <span x-show="! table">Ver como tabla</span>
                        <span x-show="table" x-cloak>Ver como barras</span>
                    </button>
                @endif
            </div>

            @if ($report->topApps === [])
                <p class="text-sm text-fg-muted">Nadie abrió apps desde el portal en este periodo.</p>
            @else
                <ul id="usage-bars" x-show="! table" class="flex flex-col gap-3">
                    @foreach ($report->topApps as $app)
                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3.5 gap-y-1 sm:grid-cols-[minmax(0,200px)_minmax(0,1fr)_56px]">
                            <span class="truncate text-sm font-semibold max-sm:col-span-2">{{ $app['name'] }}</span>
                            <span class="flex h-5 items-center">
                                <span role="img" tabindex="0" aria-label="{{ $app['name'] }}: {{ $launches($app['launches']) }}" title="{{ $launches($app['launches']) }}"
                                      class="group relative block h-5 min-w-[2px] rounded-r-[4px] bg-viz-1 focus-visible:outline-offset-2"
                                      style="width: {{ $app['width'] }}%">
                                    <span class="pointer-events-none absolute bottom-full left-0 z-10 mb-1.5 hidden rounded-md bg-fg px-2 py-1 text-xs font-semibold whitespace-nowrap text-on-fg group-hover:block group-focus-visible:block" aria-hidden="true">{{ $launches($app['launches']) }}</span>
                                </span>
                            </span>
                            <span class="text-right text-sm font-bold tabular-nums">{{ $number($app['launches']) }}</span>
                        </li>
                    @endforeach
                </ul>

                <div x-show="table" x-cloak class="overflow-x-auto">
                    <table id="usage-table" class="w-full border-collapse text-sm">
                        <caption class="sr-only">Apps más abiertas en {{ $days }}</caption>
                        <thead>
                            <tr class="border-b border-line text-left">
                                <th scope="col" class="py-2 pr-4 font-bold">App</th>
                                <th scope="col" class="py-2 text-right font-bold">Aperturas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($report->topApps as $app)
                                <tr class="border-b border-line last:border-b-0">
                                    <th scope="row" class="py-2 pr-4 text-left font-semibold">{{ $app['name'] }}</th>
                                    <td class="py-2 text-right tabular-nums">{{ $number($app['launches']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="text-[0.8125rem] text-fg-muted">Aperturas desde el portal en {{ $days }}, hasta {{ UsageReport::TOP_APPS }} apps. En las apps locales cuenta las veces que se abrió su ficha.</p>
        </section>

        <section aria-labelledby="usage-idle-title" class="flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface px-5 py-5 sm:px-[22px]">
            <h2 id="usage-idle-title" class="font-sans text-lg font-bold tracking-normal">Sin uso en 90 días</h2>
            @if ($report->unusedApps === [])
                <p class="flex items-center gap-3 rounded-control bg-status-active-bg p-3.5 text-sm font-semibold text-status-active">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                    Todas las apps se usaron al menos una vez en 90 días.
                </p>
            @else
                <ul class="flex flex-col divide-y divide-line rounded-[14px] border border-line">
                    @foreach ($report->unusedApps as $app)
                        <li class="flex flex-col gap-0.5 px-3.5 py-2.5">
                            <span class="text-sm font-semibold">{{ $app['name'] }}</span>
                            <span class="text-[0.8125rem] text-fg-muted">
                                @if ($app['last_launched_at'])
                                    última vez el {{ $app['last_launched_at']->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
                                @else
                                    nunca se ha abierto desde el portal
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="text-[0.8125rem] leading-normal text-fg-soft">Sin contar las apps ocultas. Puedes ocultar una app sin uso o revisar si sus accesos están bien asignados.</p>
        </section>
    </div>
</x-layouts.admin>
