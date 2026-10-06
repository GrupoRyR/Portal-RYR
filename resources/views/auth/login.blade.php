{{-- Neutral sign-in (docs/diseno/Login.dc.html): no company is known yet, so no brand color. --}}
<x-layouts.app title="Iniciar sesión" class="bg-[#FAFAF8] dark:bg-ground">
    <main class="mx-auto flex min-h-dvh w-full max-w-md flex-col px-7 pt-16 pb-8">
        {{-- Decorative app tiles: the portal is a home for many apps. --}}
        <div aria-hidden="true" class="flex flex-col gap-3.5">
            <div class="flex gap-3.5">
                <span class="flex size-[68px] -rotate-6 items-center justify-center rounded-[20px] bg-[#EEF0FF]"><svg class="size-[34px]" viewBox="0 0 24 24" fill="none" stroke="#4338CA" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4.5" width="14" height="16.5" rx="2.5"/><path d="M9.5 3h5v3h-5z"/><path d="M8.5 11h7M8.5 14.5h7M8.5 18h4"/></svg></span>
                <span class="mt-[18px] flex size-[68px] items-center justify-center rounded-[20px] bg-[#E7F6EF]"><svg class="size-[34px]" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M10 16l4.5-4.5M11.5 11.5h3v3"/></svg></span>
                <span class="flex size-[68px] rotate-[5deg] items-center justify-center rounded-[20px] bg-[#FDF3E3]"><svg class="size-[34px]" viewBox="0 0 24 24" fill="none" stroke="#B45309" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 8V7a2 2 0 0 1 2-2h10v3"/><rect x="4" y="8" width="16" height="11" rx="2.5"/><path d="M20 11.5h-3.5a2 2 0 0 0 0 4H20"/></svg></span>
            </div>
            <div class="flex gap-3.5 pl-[41px]">
                <span class="flex size-[68px] rotate-[4deg] items-center justify-center rounded-[20px] bg-[#F3EEFF]"><svg class="size-[34px]" viewBox="0 0 24 24" fill="none" stroke="#6D28D9" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></svg></span>
                <span class="mt-[18px] flex size-[68px] -rotate-[4deg] items-center justify-center rounded-[20px] bg-[#EDF0F4]"><svg class="size-[34px]" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="12" height="10" rx="2.5"/><path d="M15 10.5l6-3.5v10l-6-3.5"/><circle cx="7" cy="10.5" r="0.6"/></svg></span>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-3.5">
            <h1 class="text-[2.5rem] leading-[1.05] font-bold tracking-[-0.025em] break-words hyphens-auto">Todas tus aplicaciones en un solo lugar</h1>
            <p class="text-base leading-normal text-fg-soft">Entra con la misma cuenta de Microsoft que usas para tu correo de trabajo.</p>
        </div>

        <div class="mt-auto flex flex-col gap-5 pt-12">
            @error('login')
                <p role="alert" class="rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p>
            @enderror

            <a href="{{ route('auth.microsoft.redirect') }}"
               class="flex h-14 items-center justify-center gap-3 rounded-icon bg-fg text-[1.0625rem] font-semibold text-on-fg hover:opacity-90">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l4-4-4-4M14 12H4"/></svg>
                Entrar con Microsoft
            </a>

            @if ($companies->isNotEmpty())
                <div class="flex flex-col items-center gap-2.5 border-t border-line pt-[18px]">
                    <p class="text-xs font-semibold tracking-[0.06em] text-fg-muted uppercase">Grupo Empresarial RYR</p>
                    <ul role="list" class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                        @foreach ($companies as $company)
                            <li class="font-display text-base font-bold tracking-[0.04em] text-fg-soft uppercase">
                                @if ($company->logo_light_path)
                                    <picture>
                                        @if ($company->logo_dark_path)
                                            <source srcset="{{ $company->brandFileUrl($company->logo_dark_path) }}" media="(prefers-color-scheme: dark)">
                                        @endif
                                        @php($logoSize = $company->brandImageSize($company->logo_light_path))
                                        <img src="{{ $company->brandFileUrl($company->logo_light_path) }}" alt="{{ $company->name }}" @if ($logoSize) width="{{ $logoSize[0] }}" height="{{ $logoSize[1] }}" @endif class="h-7 w-auto max-w-32 object-contain" loading="lazy" decoding="async">
                                    </picture>
                                @else
                                    {{ $company->short_name ?: $company->name }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </main>
</x-layouts.app>
