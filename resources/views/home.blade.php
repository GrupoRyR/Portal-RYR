{{-- Placeholder home page; the application catalog arrives in Phase 4. --}}
@php($user = auth()->user())
@php($company = $user->company)
<x-layouts.app title="Inicio">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3 sm:px-6">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-brand-button font-display text-lg font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($company?->short_name ?: ($company?->name ?? config('app.name')), 0, 1)) }}</span>
            <span class="truncate font-display text-lg font-bold">{{ $company?->name ?? config('app.name') }}</span>

            <div class="ml-auto flex items-center gap-2">
                @if ($user->hasAnyRole(['super_admin', 'portal_admin']))
                    <a href="{{ route('admin.index') }}" class="inline-flex min-h-touch items-center rounded-control px-3 text-sm font-semibold text-brand-ink hover:bg-brand-tint">Administración</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-touch items-center rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold hover:bg-subtle">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </header>

    <main id="contenido" class="mx-auto flex max-w-5xl flex-col gap-2 px-4 py-8 sm:px-6">
        <h1 class="text-[2rem] leading-tight font-bold sm:text-4xl">Hola, {{ $user->name }}</h1>
        <p class="text-[0.9375rem] text-fg-muted">Muy pronto verás aquí todas tus aplicaciones.</p>
    </main>
</x-layouts.app>
