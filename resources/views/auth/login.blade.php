{{--
    Sign-in «Vitrina» (approved sketch, 2026-11-04): photos of the companies
    fade one into the next behind a neutral veil, under one glass card. No
    company is known yet, so the page stays neutral (data-brand="neutral"):
    brand color lives only in the One mark and in each company's logo chip.
    CSS only, no JavaScript (app.css, "Sign-in Vitrina"); with reduced motion
    a single photo stays still.
--}}
@use('App\Branding\BrandPalette')
<x-layouts.app title="Iniciar sesión" class="vitrina">
    <div class="vitrina-slides" aria-hidden="true">
        <div class="vitrina-slide"></div>
        <div class="vitrina-slide"></div>
        <div class="vitrina-slide"></div>
    </div>
    <div class="vitrina-veil" aria-hidden="true"></div>

    <main class="flex grow items-center justify-center px-4 py-12 max-sm:py-8">
        <section aria-labelledby="titulo" class="g-card flex w-full max-w-[460px] flex-col items-center gap-5 px-10 pt-11 pb-9 text-center max-sm:px-5.5 max-sm:pt-8 max-sm:pb-7">
            {{-- One mark: color on light glass, crystal on dark glass. Only
                 the variant for the scheme downloads. Without JavaScript it
                 follows the system; the theme script rewrites the media of
                 scheme-bound sources when a theme was chosen. --}}
            <picture>
                <source srcset="{{ asset('images/one/one-cristal.webp') }}" media="(prefers-color-scheme: dark)" width="220" height="103">
                <img src="{{ asset('images/one/one-color.webp') }}" width="220" height="103" alt="One, portal de Segurex y Soluciones y Derivados" fetchpriority="high" class="h-auto w-[220px]">
            </picture>

            <div class="flex flex-col gap-2.5">
                <h1 id="titulo" class="text-[2.125rem] leading-[1.15] font-bold tracking-[-0.015em] text-balance max-sm:text-[1.75rem]">Bienvenido a One</h1>
                <p class="text-base leading-normal">Tus apps, anuncios y tareas de Segurex y Soluciones y Derivados, en un solo lugar.</p>
            </div>

            @error('login')
                <p role="alert" class="w-full rounded-(--g-r-md) bg-(--g-crit-bg) px-4 py-3 text-sm font-semibold text-(--g-crit)">{{ $message }}</p>
            @enderror

            <a href="{{ route('auth.microsoft.redirect') }}" class="g-btn g-btn--ink min-h-13 w-full gap-3 text-base font-bold">
                {{-- Microsoft's four squares, as its sign-in guidelines ask. --}}
                <svg class="size-5" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect width="9" height="9" fill="#F25022"/><rect x="11" width="9" height="9" fill="#7FBA00"/><rect y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/></svg>
                Iniciar sesión con Microsoft
            </a>

            <p class="text-[0.8125rem] leading-normal">Usa tu cuenta de trabajo de Segurex o de SDC. Si tienes las dos, entra con cualquiera y cambia de empresa desde el portal.</p>

            @if ($companies->isNotEmpty())
                <ul role="list" aria-label="Empresas" class="flex w-full flex-wrap items-center justify-center gap-2.5 border-t border-(--g-line) pt-5">
                    @foreach ($companies as $company)
                        {{-- Same mark as the headers; a dark logo sits on its own company's dark color. --}}
                        <li class="text-(--g-ink-soft)" style="--brand-dark: {{ BrandPalette::forCompany($company)->dark }}">
                            <x-brand-logo :company="$company" compact />
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="text-[0.8125rem] leading-normal text-(--g-ink-soft)">
                Solo para colaboradores de Segurex y Soluciones y Derivados. ¿Problemas para entrar?
                @if ($supportUrl)
                    <a href="{{ $supportUrl }}" target="_blank" rel="noopener" class="font-semibold text-(--g-ink) underline underline-offset-2">Escribe a soporte de TI</a>.
                @else
                    Escribe a soporte de TI.
                @endif
            </p>
        </section>
    </main>

    {{-- Links render only when their address is known: the data policy, the
         terms and the SDC site have none yet, so they stay out (no "#"). --}}
    <footer class="g-foot mx-4 mb-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-6 py-4.5 text-[0.8125rem] leading-normal text-(--g-ink)">
        <div class="flex min-w-0 flex-[1_1_360px] flex-col gap-0.5">
            <span class="font-bold">© {{ now()->year }} Segurex LATAM S.A.S. · Soluciones y Derivados de Colombia S.A.S.</span>
            <span>Todos los derechos reservados. Acceso exclusivo para colaboradores autorizados.</span>
        </div>
        <nav aria-label="Enlaces del pie" class="flex flex-wrap gap-x-5 gap-y-2">
            @if ($supportUrl)
                <a href="{{ $supportUrl }}" target="_blank" rel="noopener" class="underline underline-offset-2">Soporte TI</a>
            @endif
            <a href="https://www.segurex.com" class="underline underline-offset-2">segurex.com</a>
        </nav>
    </footer>
</x-layouts.app>
