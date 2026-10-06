{{-- Auditoría: unified event list with GET filters (docs/diseno/AdminAudit.dc.html). --}}
@php
    use App\Audit\AuditEntry;
    use App\Audit\AuditFilters;

    $chip = [
        AuditEntry::CATEGORY_CHANGE => 'bg-status-scheduled-bg text-status-scheduled',
        AuditEntry::CATEGORY_LAUNCH => 'bg-status-neutral-bg text-status-neutral',
        AuditEntry::CATEGORY_SECURITY => 'bg-status-failure-bg text-status-failure',
    ];
    $control = 'h-11 rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-fg';
    $pageLink = 'inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle';
    $count = count($entries);
@endphp
<x-layouts.admin title="Auditoría">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Auditoría</h1>
            <p class="max-w-[60ch] text-[0.9375rem] text-fg-muted">Cada cambio de configuración, cada app abierta y cada inicio de sesión.</p>
        </div>
    </div>

    <div class="mt-5 overflow-hidden rounded-card border border-line bg-surface">
        <form method="GET" action="{{ route('admin.audit.index') }}" role="search"
              x-data="{ period: @js($filters->period) }"
              class="flex flex-wrap items-center gap-2.5 border-b border-line px-4 py-3.5">
            <label for="au-q" class="sr-only">Buscar persona o app</label>
            <input id="au-q" type="search" name="q" value="{{ $filters->search }}" maxlength="{{ AuditFilters::MAX_SEARCH }}"
                   placeholder="Persona o app"
                   class="{{ $control }} w-full placeholder:text-fg-muted sm:w-[240px]">

            <fieldset class="flex min-w-0 max-w-full gap-1 overflow-x-auto max-sm:w-full rounded-[10px] bg-subtle p-[3px]">
                <legend class="sr-only">Tipo de evento</legend>
                @foreach (AuditFilters::TYPES as $value => $label)
                    <label class="inline-flex h-[38px] cursor-pointer items-center rounded-lg px-3 text-[0.8125rem] font-semibold whitespace-nowrap text-fg-soft has-checked:bg-surface has-checked:font-bold has-checked:text-fg has-checked:shadow-sm has-focus-visible:outline-2 has-focus-visible:outline-fg">
                        <input type="radio" name="tipo" value="{{ $value }}" class="sr-only" @checked($filters->type === $value)
                               x-on:change="$el.form.requestSubmit()">
                        {{ $label }}
                    </label>
                @endforeach
                <span aria-disabled="true" class="inline-flex h-[38px] items-center gap-1.5 px-3 text-[0.8125rem] font-semibold whitespace-nowrap text-fg-muted">
                    API <span class="rounded-md bg-surface px-1.5 text-[10px] leading-5">Próximamente</span>
                </span>
            </fieldset>

            <label for="au-co" class="sr-only">Empresa</label>
            <select id="au-co" name="empresa" class="{{ $control }} max-sm:flex-1" x-on:change="$el.form.requestSubmit()">
                <option value="">Todas las empresas</option>
                @foreach ($companies as $option)
                    <option value="{{ $option->slug }}" @selected($filters->company?->is($option))>{{ $option->name }}</option>
                @endforeach
            </select>

            <label for="au-dt" class="sr-only">Periodo</label>
            <select id="au-dt" name="periodo" class="{{ $control }} max-sm:flex-1" x-model="period"
                    x-on:change="if (period !== 'rango') $el.form.requestSubmit()">
                @foreach (AuditFilters::PERIODS as $value => $label)
                    <option value="{{ $value }}" @selected($filters->period === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <span x-show="period === 'rango'" @if ($filters->period !== 'rango') x-cloak @endif class="flex flex-wrap items-center gap-2">
                <label for="au-from" class="text-sm text-fg-soft">Desde</label>
                <input id="au-from" type="date" name="desde" value="{{ $filters->from?->toDateString() }}" class="{{ $control }}">
                <label for="au-to" class="text-sm text-fg-soft">Hasta</label>
                <input id="au-to" type="date" name="hasta" value="{{ $filters->to?->toDateString() }}" class="{{ $control }}">
            </span>

            <button type="submit" class="inline-flex h-11 items-center rounded-[10px] bg-fg px-4 text-sm font-semibold text-on-fg">Filtrar</button>
        </form>

        @if ($count === 0)
            <p class="px-4 py-12 text-center text-[0.9375rem] text-fg-muted">No hay eventos con estos filtros.</p>
        @else
            <table class="w-full border-collapse text-sm max-md:block">
                <thead class="max-md:sr-only">
                    <tr class="border-b border-line text-left text-xs font-bold tracking-[0.06em] text-fg-muted uppercase">
                        <th scope="col" class="w-[150px] px-4 py-3">Cuándo</th>
                        <th scope="col" class="px-4 py-3">Quién</th>
                        <th scope="col" class="px-4 py-3">Qué hizo</th>
                        <th scope="col" class="px-4 py-3">Sobre qué</th>
                        <th scope="col" class="px-4 py-3">Desde</th>
                    </tr>
                </thead>
                <tbody class="max-md:block">
                    @foreach ($entries as $entry)
                        <tr class="border-b border-line align-top last:border-b-0 max-md:flex max-md:flex-wrap max-md:items-center max-md:gap-x-3 max-md:gap-y-1.5 max-md:px-4 max-md:py-3">
                            <td class="px-4 py-3 whitespace-nowrap text-fg-muted max-md:order-2 max-md:p-0 max-md:text-xs">
                                <time datetime="{{ $entry->occurredAt->toIso8601String() }}">{{ $entry->when() }}</time>
                            </td>
                            <td class="px-4 py-3 font-semibold break-words max-md:order-1 max-md:p-0">{{ $entry->who }}</td>
                            <td class="px-4 py-3 max-md:order-3 max-md:p-0">
                                <span class="inline-block rounded-md px-2 py-0.5 text-[0.8125rem] font-semibold whitespace-nowrap {{ $chip[$entry->category] }}">{{ $entry->categoryLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 break-words max-md:order-4 max-md:basis-full max-md:p-0">
                                @if ($entry->changes !== [])
                                    <details class="group">
                                        <summary class="flex cursor-pointer list-none items-start gap-1.5 [&::-webkit-details-marker]:hidden">
                                            <svg class="mt-0.5 size-4 shrink-0 text-fg-muted transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                            <span>{{ $entry->what }} <span class="sr-only">(ver cambios)</span></span>
                                        </summary>
                                        <div class="mt-2.5 overflow-x-auto">
                                            <table class="w-full min-w-[320px] border-collapse overflow-hidden rounded-[10px] border border-line text-[0.8125rem]">
                                                <thead>
                                                    <tr class="bg-ground text-left">
                                                        <th scope="col" class="px-3 py-2 font-bold">Campo</th>
                                                        <th scope="col" class="px-3 py-2 font-bold">Antes</th>
                                                        <th scope="col" class="px-3 py-2 font-bold">Después</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($entry->changes as $change)
                                                        <tr class="border-t border-line">
                                                            <th scope="row" class="px-3 py-2 text-left font-normal">{{ $change['label'] }}</th>
                                                            <td class="px-3 py-2 font-mono break-all text-status-failure line-through decoration-1">{{ $change['old'] }}</td>
                                                            <td class="px-3 py-2 font-mono break-all text-status-active">{{ $change['new'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                @else
                                    {{ $entry->what }}
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-fg-muted max-md:order-2 max-md:p-0 max-md:text-xs">
                                @if ($entry->device)
                                    {{ $entry->deviceLabel() }}
                                @else
                                    <span aria-label="Sin dispositivo">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <nav aria-label="Páginas" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-sm text-fg-muted">
            <span>Mostrando {{ $count }} {{ $count === 1 ? 'evento' : 'eventos' }}</span>
            <span class="flex gap-2">
                @if ($paged)
                    <a href="{{ route('admin.audit.index', $filters->query()) }}" class="{{ $pageLink }} text-fg">Más recientes</a>
                @endif
                @if ($next)
                    <a href="{{ route('admin.audit.index', [...$filters->query(), 'cursor' => $next]) }}" rel="next" class="{{ $pageLink }} text-fg">Más antiguos</a>
                @endif
            </span>
        </nav>
    </div>
</x-layouts.admin>
