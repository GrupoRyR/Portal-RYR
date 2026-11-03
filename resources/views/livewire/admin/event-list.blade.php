{{-- Events administration list (App\Livewire\Admin\EventList), laid out like the announcements list. --}}
@php($tabs = ['' => 'Todos', ...\App\Livewire\Admin\EventList::TAB_LABELS])
<div>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Eventos</h1>
            <p class="text-[0.9375rem] text-fg-muted">Se muestran en «Próximas fechas» del inicio y en Eventos hasta que terminan, con «Agregar a mi calendario».</p>
        </div>
        @can('create', \App\Models\Event::class)
            <a href="{{ route('admin.events.create') }}"
               class="inline-flex h-11 items-center gap-2 rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Nuevo evento
            </a>
        @endcan
    </div>

    <nav aria-label="Estado" class="mt-6 max-w-full overflow-x-auto">
        <ul class="flex w-max gap-1.5 rounded-control bg-subtle p-1">
            @foreach ($tabs as $value => $label)
                @php($isCurrent = $current === $value)
                <li>
                    <button type="button" wire:click="$set('status', '{{ $value }}')"@if ($isCurrent) aria-current="page"@endif
                            @class([
                                'inline-flex h-10 items-center rounded-[9px] px-3.5 text-sm whitespace-nowrap',
                                'bg-surface font-bold shadow-sm' => $isCurrent,
                                'font-semibold text-fg-soft hover:text-fg' => ! $isCurrent,
                            ])>{{ $label }} · {{ $counts[$value] }}</button>
                </li>
            @endforeach
        </ul>
    </nav>

    @if ($rows->isEmpty())
        <p class="mt-5 rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">
            {{ $current !== '' ? 'No hay eventos con este estado.' : 'Todavía no hay eventos.' }}
        </p>
    @else
        <ul class="mt-5 flex flex-col gap-3">
            @foreach ($rows as $row)
                <li wire:key="event-{{ $row['event']->id }}">
                    <a href="{{ route('admin.events.edit', $row['event']) }}"
                       class="flex items-center gap-3 rounded-[16px] border border-line bg-surface p-3 hover:border-line-strong sm:gap-[18px] sm:p-4">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-muted">
                            <x-admin.nav-icon name="calendar" class="size-6" />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <span @class([
                                'min-w-0 text-base font-bold break-words',
                                'text-fg-soft' => in_array($row['status'], [\App\Models\Event::STATUS_PAST, \App\Models\Event::STATUS_WITHDRAWN], true),
                            ])>{{ $row['event']->title }}</span>
                            <span class="text-sm text-fg-muted">{{ $row['details'] }}</span>
                            <x-admin.event-status :status="$row['status']" class="self-start sm:hidden" />
                        </span>
                        <x-admin.event-status :status="$row['status']" class="max-sm:hidden" />
                        <svg class="size-[18px] shrink-0 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($events->hasPages())
            <nav aria-label="Páginas" class="mt-5 flex items-center justify-between gap-3 text-sm font-semibold">
                @if ($events->onFirstPage())
                    <span></span>
                @else
                    <button type="button" wire:click="previousPage" rel="prev" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">Anterior</button>
                @endif
                <span class="text-fg-muted">Página {{ $events->currentPage() }} de {{ $events->lastPage() }}</span>
                @if ($events->hasMorePages())
                    <button type="button" wire:click="nextPage" rel="next" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">Siguiente</button>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
    @endif
</div>
