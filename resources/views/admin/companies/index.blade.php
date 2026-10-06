{{-- Companies administration: list + editor (docs/diseno/AdminCompanies.dc.html). --}}
<x-layouts.admin title="Empresas">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-[2rem] leading-tight font-bold">Empresas</h1>
            <p class="text-[0.9375rem] text-fg-muted">Cada empresa entra con su propia organización de Microsoft y ve el portal con su marca.</p>
        </div>
        <a href="{{ route('admin.companies.create') }}"
           class="inline-flex h-11 items-center gap-2 rounded-control bg-brand-button px-[18px] text-[0.9375rem] font-semibold text-white">
            <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Nueva empresa
        </a>
    </div>

    @if ($selected === null)
        <section class="mt-6 flex max-w-xl flex-col gap-2 rounded-card border border-line bg-surface p-6">
            <h2 class="font-sans text-lg font-bold tracking-normal">Todavía no hay empresas registradas.</h2>
            <p class="text-fg-soft">Agrega la primera con «Nueva empresa».</p>
        </section>
    @else
        <div class="mt-6 grid gap-6 xl:grid-cols-[260px_minmax(0,1fr)] xl:items-start">
            <nav aria-label="Lista de empresas">
                <ul role="list" class="flex gap-2.5 overflow-x-auto pb-1 xl:flex-col xl:overflow-visible xl:pb-0">
                    @foreach ($companies as $company)
                        @php($current = $company->is($selected))
                        <li class="min-w-60 xl:min-w-0">
                            <a href="{{ route('admin.companies.show', $company) }}"@if ($current) aria-current="true"@endif
                               @class([
                                   'flex gap-3 rounded-2xl bg-surface p-3.5',
                                   'border-2 border-fg' => $current,
                                   'border border-line hover:border-line-strong' => ! $current,
                               ])>
                                @if ($company->icon_path)
                                    <img src="{{ $company->brandFileUrl($company->icon_path) }}" alt="" class="size-10 shrink-0 rounded-[11px] object-cover">
                                @else
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-[11px] font-display text-lg font-bold text-white"
                                          style="background-color: {{ \App\Branding\BrandPalette::forCompany($company)->button }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($company->short_name ?: $company->name, 0, 1)) }}</span>
                                @endif
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    <span class="truncate text-[0.9375rem] font-bold">{{ $company->name }}</span>
                                    <span class="truncate text-[0.8125rem] text-fg-muted">{{ implode(', ', $company->domains ?? []) }}</span>
                                    <span class="flex flex-wrap gap-1.5">
                                        @if ($company->is_active)
                                            <span class="rounded-md bg-status-active-bg px-2 py-0.5 text-xs font-semibold text-status-active">Activa</span>
                                        @else
                                            <span class="rounded-md bg-status-failure-bg px-2 py-0.5 text-xs font-semibold text-status-failure">Inactiva</span>
                                        @endif
                                        @if ($company->is_primary_for_tenant)
                                            <span class="rounded-md bg-status-neutral-bg px-2 py-0.5 text-xs font-semibold text-status-neutral">Principal</span>
                                        @endif
                                    </span>
                                </span>
                            </a>
                            @if (! $company->is_active && $company->consent_verified_at === null)
                                <p class="mt-1.5 flex items-center justify-between gap-2 px-1 text-[0.8125rem]">
                                    <span class="text-fg-muted">Paso 2 de 4 · Consentimiento</span>
                                    <a href="{{ route('admin.companies.wizard', $company) }}" class="inline-flex min-h-touch items-center font-semibold text-brand-ink underline-offset-4 hover:underline">Continuar<span class="sr-only"> el asistente de {{ $company->name }}</span></a>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </nav>

            <livewire:admin.company-editor :company="$selected" :key="'company-'.$selected->id" />
        </div>
    @endif
</x-layouts.admin>
