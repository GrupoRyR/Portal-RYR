{{--
    «Estado de TI» of «Mi día» (odd/tasks/inicio-bloques.md H4,
    App\ItStatus\ItStatus): the overall headline of the user's company,
    what is failing (worst first: the manual services and the visible apps
    in maintenance or with a current failure report) and «Reportar un
    problema» (/soporte, to Gestor TI) when a support URL is configured.
--}}
@props(['status'])

@php
    $tone = fn (\App\ItStatus\ItStatusLevel $level) => match ($level) {
        \App\ItStatus\ItStatusLevel::Operational => 'bg-status-active-bg text-status-active',
        \App\ItStatus\ItStatusLevel::Degraded => 'bg-status-maintenance-bg text-status-maintenance',
        \App\ItStatus\ItStatusLevel::Down => 'bg-status-failure-bg text-status-failure',
    };
    // Worst first; within a level the services (the admin's order) before the apps (catalog order).
    $items = collect([...$status->services, ...$status->apps])
        ->sortBy(fn (\App\ItStatus\ItStatusItem $item) => -$item->level->severity())
        ->values();
@endphp

<section id="estado-ti" aria-labelledby="estado-ti-titulo" {{ $attributes->merge(['class' => 'flex scroll-mt-6 flex-col gap-3.5 p-5']) }}>
    <h2 id="estado-ti-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Estado de TI</h2>

    <p @class(['flex items-center gap-2 self-start rounded-control px-3 py-2 text-sm font-semibold', $tone($status->level)])>
        <span aria-hidden="true" class="size-2 shrink-0 rounded-full bg-current"></span>{{ $status->level->headline() }}
    </p>

    @if ($items->isNotEmpty())
        <ul class="flex flex-col gap-3">
            @foreach ($items as $item)
                <li data-it-item class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p class="text-sm leading-snug font-bold break-words">{{ $item->name }}</p>
                        @if ($item->message)
                            <p class="text-xs break-words text-fg-muted">{{ $item->message }}</p>
                        @endif
                        @if ($item->until)
                            @php($until = $item->until->setTimezone(config('app.timezone')))
                            <p class="text-xs text-fg-muted">Hasta el <time datetime="{{ $until->toIso8601String() }}">{{ str_replace('.', '', $until->locale('es')->isoFormat('D MMM')) }}, {{ $until->format('H:i') }}</time></p>
                        @endif
                    </div>
                    <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold', $tone($item->level)])>{{ $item->label }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($status->reportHref)
        <a href="{{ $status->reportHref }}" target="_blank" rel="noopener" class="inline-flex min-h-touch items-center gap-2 self-start rounded-control border border-line-strong px-3 text-[0.8125rem] font-semibold hover:bg-subtle">
            <x-home.icon name="lifebuoy" class="size-[18px] text-fg-muted" />Reportar un problema
        </a>
    @endif
</section>
