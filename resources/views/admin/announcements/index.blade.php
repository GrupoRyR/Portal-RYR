{{-- Announcements administration: status tabs, company filter and list (docs/diseno/AdminAnnouncements.dc.html). --}}
@php
    $tabs = ['' => 'Todos', ...\App\View\Admin\AnnouncementAdminRow::TAB_LABELS];
    $tabUrl = fn (string $value) => route('admin.announcements.index', array_filter([
        'empresa' => $company?->slug,
        'estado' => $value,
    ]));
@endphp
<x-layouts.admin title="Anuncios">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Anuncios</h1>
            <p class="text-[0.9375rem] text-fg-muted">Se muestran en el inicio del portal entre su fecha de inicio y de fin.</p>
        </div>
        @can('create', \App\Models\Announcement::class)
            <a href="{{ route('admin.announcements.create') }}"
               class="inline-flex h-11 items-center gap-2 rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Nuevo anuncio
            </a>
        @endcan
    </div>

    @if (session('status'))
        <p role="status" class="mt-5 rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">{{ session('status') }}</p>
    @endif

    <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
        <nav aria-label="Estado" class="max-w-full overflow-x-auto">
            <ul class="flex w-max gap-1.5 rounded-control bg-subtle p-1">
                @foreach ($tabs as $value => $label)
                    @php($current = ($status ?? '') === $value)
                    <li>
                        <a href="{{ $tabUrl($value) }}"@if ($current) aria-current="page"@endif
                           @class([
                               'inline-flex h-10 items-center rounded-[9px] px-3.5 text-sm whitespace-nowrap',
                               'bg-surface font-bold shadow-sm' => $current,
                               'font-semibold text-fg-soft hover:text-fg' => ! $current,
                           ])>{{ $label }} · {{ $counts[$value] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @if ($companies->isNotEmpty())
            <form method="GET" action="{{ route('admin.announcements.index') }}" class="flex items-center gap-2 max-sm:w-full">
                @if ($status)
                    <input type="hidden" name="estado" value="{{ $status }}">
                @endif
                <label for="an-co" class="sr-only">Empresa</label>
                <select id="an-co" name="empresa" x-data x-on:change="$el.form.requestSubmit()"
                        class="h-11 rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-fg max-sm:flex-1">
                    <option value="">Todas las empresas</option>
                    @foreach ($companies as $option)
                        <option value="{{ $option->slug }}" @selected($company?->is($option))>{{ $option->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="inline-flex h-11 items-center rounded-[10px] border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle">Filtrar</button>
            </form>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="mt-5 rounded-card border border-line bg-surface px-4 py-10 text-center text-[0.9375rem] text-fg-muted">
            @if ($status)
                No hay anuncios con este estado.
            @elseif ($company)
                No hay anuncios para esta empresa.
            @else
                Todavía no hay anuncios.
            @endif
        </p>
    @else
        <ul class="mt-5 flex flex-col gap-3">
            @foreach ($rows as $row)
                <li>
                    <a href="{{ route('admin.announcements.edit', $row->announcement) }}"
                       class="flex items-center gap-3 rounded-[16px] border border-line bg-surface p-3 hover:border-line-strong sm:gap-[18px] sm:p-4">
                        @if ($row->imageUrl)
                            <img src="{{ $row->imageUrl }}" alt="" width="1280" height="720" loading="lazy" decoding="async"
                                 class="aspect-video w-20 shrink-0 rounded-[10px] bg-subtle object-cover sm:w-[120px]">
                        @else
                            <span class="flex aspect-video w-20 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-muted sm:w-[120px]">
                                <x-admin.nav-icon name="megaphone" class="size-6" />
                            </span>
                        @endif
                        <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <span class="flex items-center gap-2">
                                @if ($row->announcement->is_pinned)
                                    <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Fijado"><path d="M9 4h6l-1 6 4 3H6l4-3zM12 13v7"/></svg>
                                @endif
                                <span @class([
                                    'min-w-0 text-base font-bold break-words',
                                    'text-fg-soft' => in_array($row->status, [\App\Models\Announcement::STATUS_EXPIRED, \App\Models\Announcement::STATUS_WITHDRAWN], true),
                                ])>{{ $row->announcement->title }}</span>
                            </span>
                            <span class="text-sm text-fg-muted">{{ $row->details }}</span>
                            <x-admin.announcement-status :status="$row->status" class="self-start sm:hidden" />
                        </span>
                        <x-admin.announcement-status :status="$row->status" class="max-sm:hidden" />
                        <svg class="size-[18px] shrink-0 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($announcements->hasPages())
            <nav aria-label="Páginas" class="mt-5 flex items-center justify-between gap-3 text-sm font-semibold">
                @if ($announcements->previousPageUrl())
                    <a href="{{ $announcements->previousPageUrl() }}" rel="prev" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">Anterior</a>
                @else
                    <span></span>
                @endif
                <span class="text-fg-muted">Página {{ $announcements->currentPage() }} de {{ $announcements->lastPage() }}</span>
                @if ($announcements->nextPageUrl())
                    <a href="{{ $announcements->nextPageUrl() }}" rel="next" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 hover:bg-subtle">Siguiente</a>
                @else
                    <span></span>
                @endif
            </nav>
        @endif
    @endif
</x-layouts.admin>
