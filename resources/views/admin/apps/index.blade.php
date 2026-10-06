{{-- Apps administration: tiles, filters and list (docs/diseno/AdminApps.dc.html). --}}
@php
    $select = 'h-11 rounded-[10px] border border-line-strong bg-surface px-3 text-sm text-fg';
    $chip = [
        'activa' => 'bg-status-active-bg text-status-active',
        'fallas' => 'bg-status-failure-bg text-status-failure',
        'mantenimiento' => 'bg-status-maintenance-bg text-status-maintenance',
        'oculta' => 'bg-status-neutral-bg text-status-neutral',
    ];
    $microsoftShare = $tiles['web'] > 0 ? round($tiles['microsoft'] / $tiles['web'] * 100) : 0;
@endphp
<x-layouts.admin title="Aplicaciones">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Aplicaciones</h1>
            <p class="text-[0.9375rem] text-fg-muted">Lo que cada persona ve en su portal sale de aquí.</p>
        </div>
        <a href="{{ route('admin.apps.create') }}"
           class="inline-flex h-11 items-center gap-2 rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">
            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Nueva aplicación
        </a>
    </div>

    @if (session('status'))
        <p role="status" class="mt-5 rounded-control bg-status-active-bg px-4 py-3 text-sm font-semibold text-status-active">{{ session('status') }}</p>
    @endif

    <dl class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div data-tile="published" class="flex flex-col gap-0.5 rounded-[14px] border border-line bg-surface px-4 py-3.5">
            <dt class="text-[0.8125rem] text-fg-muted">Publicadas</dt>
            <dd class="font-display text-[1.625rem] font-bold">{{ $tiles['published'] }}</dd>
        </div>
        <div data-tile="failing" @class([
            'flex flex-col gap-0.5 rounded-[14px] border bg-surface px-4 py-3.5',
            'border-status-failure-line' => $tiles['failing'] > 0,
            'border-line' => $tiles['failing'] === 0,
        ])>
            <dt @class(['text-[0.8125rem]', 'font-semibold text-status-failure' => $tiles['failing'] > 0, 'text-fg-muted' => $tiles['failing'] === 0])>Con fallas</dt>
            <dd class="font-display text-[1.625rem] font-bold">{{ $tiles['failing'] }}</dd>
        </div>
        <div data-tile="maintenance" class="flex flex-col gap-0.5 rounded-[14px] border border-line bg-surface px-4 py-3.5">
            <dt class="text-[0.8125rem] text-fg-muted">En mantenimiento</dt>
            <dd class="font-display text-[1.625rem] font-bold">{{ $tiles['maintenance'] }}</dd>
        </div>
        <div data-tile="microsoft" class="flex flex-col gap-1.5 rounded-[14px] border border-line bg-surface px-4 py-3.5">
            <dt class="text-[0.8125rem] text-fg-muted">Entran con Microsoft</dt>
            <dd class="flex items-center gap-2.5">
                <span class="font-display text-[1.625rem] font-bold whitespace-nowrap">{{ $tiles['microsoft'] }} de {{ $tiles['web'] }}</span>
                <span class="h-1.5 min-w-8 flex-1 overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                    <span class="block h-1.5 bg-fg" style="width: {{ $microsoftShare }}%"></span>
                </span>
            </dd>
        </div>
    </dl>

    <section class="mt-5 overflow-hidden rounded-card border border-line bg-surface" aria-labelledby="apps-list-title">
        <h2 id="apps-list-title" class="sr-only">Lista de aplicaciones</h2>
        <form role="search" method="GET" action="{{ route('admin.apps.index') }}"
              class="flex flex-wrap items-end gap-2.5 border-b border-line px-4 py-3.5">
            <div class="flex min-w-56 flex-1 flex-col gap-1">
                <label for="a-q" class="sr-only">Buscar aplicación</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-3 size-[18px] -translate-y-1/2 text-fg-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    <input id="a-q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" placeholder="Nombre o identificador"
                           class="h-11 w-full rounded-[10px] border border-line-strong bg-surface pr-3 pl-10 text-sm text-fg placeholder:text-fg-muted">
                </div>
            </div>
            <label for="a-co" class="sr-only">Empresa</label>
            <select id="a-co" name="empresa" class="{{ $select }} max-sm:flex-1">
                <option value="">Todas las empresas</option>
                @foreach ($companies as $company)
                    <option value="{{ $company->slug }}" @selected($filters['empresa'] === $company->slug)>{{ $company->name }}</option>
                @endforeach
            </select>
            <label for="a-st" class="sr-only">Estado</label>
            <select id="a-st" name="estado" class="{{ $select }} max-sm:flex-1">
                <option value="">Todos los estados</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters['estado'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
            <label for="a-ca" class="sr-only">Categoría</label>
            <select id="a-ca" name="categoria" class="{{ $select }} max-sm:flex-1">
                <option value="">Todas las categorías</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->slug }}" @selected($filters['categoria'] === $category->slug)>{{ $category->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="inline-flex h-11 items-center rounded-[10px] border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle">Filtrar</button>
            @if ($filtered)
                <a href="{{ route('admin.apps.index') }}" class="inline-flex min-h-touch items-center px-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">Quitar filtros</a>
            @endif
        </form>

        @if ($rows->isEmpty())
            <p class="px-4 py-10 text-center text-[0.9375rem] text-fg-muted">
                {{ $filtered ? 'Ninguna aplicación coincide con los filtros.' : 'Todavía no hay aplicaciones.' }}
            </p>
        @else
            <div class="relative overflow-x-auto" role="region" aria-labelledby="apps-list-title" tabindex="0">
                <table class="w-full min-w-[960px] border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-xs font-bold tracking-[0.06em] text-fg-muted uppercase">
                            <th scope="col" class="border-b border-line px-4 py-3">Aplicación</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Empresa</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Categoría</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Tipo</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Inicio de sesión</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Estado</th>
                            <th scope="col" class="border-b border-line px-4 py-3">Quién la ve</th>
                            <th scope="col" class="border-b border-line px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-line last:border-b-0 hover:bg-ground">
                                <td class="px-4 py-3.5">
                                    <span class="flex items-center gap-3">
                                        @include('apps.partials.icon', ['app' => $row->app, 'class' => 'size-9 text-base'])
                                        <span class="flex min-w-0 flex-col">
                                            <span class="font-semibold">{{ $row->app->name }}</span>
                                            <code class="text-xs text-fg-muted">{{ $row->app->slug }}</code>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">{{ $row->companies }}</td>
                                <td class="px-4 py-3.5">{{ $row->category }}</td>
                                <td class="px-4 py-3.5 whitespace-nowrap">{{ $row->type }}</td>
                                <td class="px-4 py-3.5">
                                    <span @class(['text-[0.8125rem] font-semibold', 'text-status-active' => $row->app->login_type === 'microsoft', 'text-fg-soft' => $row->app->login_type !== 'microsoft'])>{{ $row->login }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex flex-col gap-0.5">
                                        <span class="inline-flex items-center gap-1.5 self-start rounded-lg px-2.5 py-1 text-[0.8125rem] font-semibold whitespace-nowrap {{ $chip[$row->status->value] }}">
                                            @if ($row->status->value === 'activa')
                                                <span class="size-[7px] rounded-full bg-status-active-dot" aria-hidden="true"></span>
                                            @endif
                                            {{ $row->status->label() }}
                                        </span>
                                        @if ($row->statusDetail)
                                            <span class="text-xs text-fg-muted">{{ $row->statusDetail }}</span>
                                        @endif
                                    </span>
                                </td>
                                <td class="max-w-56 px-4 py-3.5 text-fg-soft">{{ $row->audience }}</td>
                                <td class="px-4 py-3.5 text-right">
                                    <a href="{{ route('admin.apps.edit', $row->app) }}" class="inline-flex min-h-touch items-center px-2 font-semibold text-brand-ink underline-offset-4 hover:underline">Editar<span class="sr-only"> {{ $row->app->name }}</span></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.admin>
