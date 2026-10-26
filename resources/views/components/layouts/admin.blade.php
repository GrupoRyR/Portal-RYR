{{--
    Administration shell (docs/diseno/AdminCompanies.dc.html).
    Desktop (≥1024px): fixed 248px sidebar. Below: off-canvas menu opened by
    a real button; Escape, the close button or the backdrop close it.
--}}
<x-layouts.app :title="$title ? $title.' · Administración' : 'Administración'" noindex livewire>
    <a href="#contenido"
       class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-fg focus:px-4 focus:py-3 focus:font-semibold focus:text-on-fg">
        Saltar al contenido
    </a>

    <div x-data="{ open: false }"
         data-menu="closed"
         x-bind:data-menu="open ? 'open' : 'closed'"
         x-on:keydown.escape.window="if (open) { open = false; $nextTick(() => $refs.toggle.focus()) }"
         class="group/shell min-h-dvh lg:flex">

        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-surface px-4 py-2 lg:hidden">
            <button type="button"
                    x-ref="toggle"
                    aria-controls="admin-sidebar"
                    aria-expanded="false"
                    x-bind:aria-expanded="open.toString()"
                    x-on:click="open = true; $nextTick(() => $refs.close.focus())"
                    class="-ml-2 inline-flex size-touch items-center justify-center rounded-control text-fg hover:bg-subtle">
                <x-admin.nav-icon name="menu" class="size-6" />
                <span class="sr-only">Abrir menú de administración</span>
            </button>
            <x-brand-logo :company="$company" />
        </header>

        <div x-on:click="open = false; $nextTick(() => $refs.toggle.focus())"
             class="fixed inset-0 z-30 hidden bg-black/40 group-data-[menu=open]/shell:block lg:hidden!"
             aria-hidden="true"></div>

        <nav id="admin-sidebar"
             aria-label="Administración"
             x-trap="open"
             class="fixed inset-y-0 left-0 z-40 flex w-[248px] shrink-0 flex-col gap-0.5 overflow-y-auto border-r border-line bg-surface px-3.5 py-5 transition-[translate] duration-200 max-lg:invisible max-lg:-translate-x-full group-data-[menu=open]/shell:max-lg:visible group-data-[menu=open]/shell:max-lg:translate-x-0 lg:sticky lg:top-0 lg:h-dvh">

            <div class="flex items-center gap-2.5 px-2 pt-1 pb-6">
                <x-brand-logo :company="$company">
                    <span class="text-xs text-fg-muted">Administración</span>
                </x-brand-logo>
                <button type="button"
                        x-ref="close"
                        x-on:click="open = false; $nextTick(() => $refs.toggle.focus())"
                        class="ml-auto inline-flex size-touch shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle lg:hidden">
                    <x-admin.nav-icon name="close" class="size-5" />
                    <span class="sr-only">Cerrar menú</span>
                </button>
            </div>

            @foreach ($navigation() as $group)
                @if ($group['label'])
                    <h2 class="px-3 pt-4 pb-1.5 font-sans text-[11px] font-bold tracking-[0.08em] text-fg-muted uppercase first-of-type:pt-3">{{ $group['label'] }}</h2>
                @endif
                <ul class="flex flex-col gap-0.5" role="list">
                    @foreach ($group['items'] as $item)
                        <li>
                            @if ($item['route'])
                                @php($current = request()->routeIs($item['active']))
                                <a href="{{ route($item['route']) }}"@if ($current) aria-current="page"@endif
                                   @class([
                                       'flex min-h-touch items-center gap-3 rounded-[10px] px-3 text-sm',
                                       'bg-brand-tint font-bold text-brand-ink' => $current,
                                       'font-semibold text-fg-soft hover:bg-ground hover:text-fg' => ! $current,
                                   ])>
                                    <x-admin.nav-icon :name="$item['icon']" />
                                    {{ $item['label'] }}
                                </a>
                            @else
                                <span aria-disabled="true" class="flex min-h-touch items-center gap-3 rounded-[10px] px-3 text-sm font-semibold text-fg-muted">
                                    <x-admin.nav-icon :name="$item['icon']" class="opacity-70" />
                                    <span class="whitespace-nowrap">{{ $item['label'] }}</span>
                                    <span class="ml-auto rounded-md bg-subtle px-1.5 text-[10px] leading-5 font-semibold whitespace-nowrap">Próximamente</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach

            <div class="mt-auto flex flex-col gap-2.5 border-t border-line pt-4">
                <a href="{{ route('home') }}" class="flex min-h-touch items-center gap-3 rounded-[10px] px-3 text-sm font-semibold text-fg-soft hover:bg-ground hover:text-fg">
                    <x-admin.nav-icon name="back" />
                    Volver al portal
                </a>
                @if ($user)
                    <div class="flex items-center gap-2.5 px-2 py-1">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-tint text-[13px] font-bold text-brand-ink" aria-hidden="true">{{ $userInitials() }}</span>
                        <span class="flex min-w-0 flex-col leading-snug">
                            <span class="truncate text-sm font-semibold">{{ $user->name }}</span>
                            @if ($roleLabel())
                                <span class="text-xs text-fg-muted">{{ $roleLabel() }}</span>
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        </nav>

        <main id="contenido" tabindex="-1" class="min-w-0 flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:p-8">
            {{ $slot }}
        </main>
    </div>
</x-layouts.app>
