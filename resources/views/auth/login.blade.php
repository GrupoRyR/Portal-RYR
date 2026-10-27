{{--
    Neutral sign-in (docs/diseno/LoginOpcionB.html): no company is known yet,
    so no brand color outside each company's own logo chip. The apps cross the
    stage like comets (components/auth/sky) behind a glass card.
--}}
@use('App\Branding\BrandPalette')
<x-layouts.app title="Iniciar sesión" class="signin-stage">
    <main class="relative grid min-h-dvh place-items-center overflow-hidden px-6 py-12 max-md:place-items-end max-md:px-4 max-md:pt-42 max-md:pb-6">
        <x-auth.sky />

        <section aria-labelledby="titulo" class="signin-card relative z-10 w-full max-w-[480px] rounded-[28px] px-11 pt-11 pb-9 max-md:rounded-3xl max-md:px-6 max-md:pt-8 max-md:pb-7">
            <h1 id="titulo" class="text-[2.5rem] leading-[1.04] font-bold tracking-[-0.03em] break-words hyphens-auto max-md:text-[2rem]">Todas tus aplicaciones en un solo lugar</h1>
            <p class="mt-3.5 text-[1.0625rem] leading-normal text-fg-soft">Entra con la misma cuenta de Microsoft que usas para tu correo de trabajo.</p>

            @error('login')
                <p role="alert" class="mt-6 rounded-control bg-status-failure-bg px-4 py-3 text-sm font-semibold text-status-failure">{{ $message }}</p>
            @enderror

            <a href="{{ route('auth.microsoft.redirect') }}"
               class="mt-8 flex h-14 items-center justify-center gap-3 rounded-icon bg-fg text-[1.0625rem] font-bold text-on-fg transition-[translate,box-shadow] duration-150 hover:-translate-y-px hover:shadow-[0_10px_24px_rgb(23_25_28/0.25)]">
                {{-- Microsoft's four squares, in the button's own color. --}}
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8.5" height="8.5"/><rect x="12.5" y="3" width="8.5" height="8.5"/><rect x="3" y="12.5" width="8.5" height="8.5"/><rect x="12.5" y="12.5" width="8.5" height="8.5"/></svg>
                Entrar con Microsoft
            </a>

            @if ($companies->isNotEmpty())
                <div class="mt-8 border-t border-line pt-6">
                    <p class="text-center text-[0.8125rem] font-bold text-fg-muted">Empresas del grupo</p>
                    <ul role="list" class="mt-3 flex flex-wrap items-center justify-center gap-2.5">
                        @foreach ($companies as $company)
                            {{-- Same mark as the headers; a dark logo sits on its own company's dark color. --}}
                            <li class="text-fg-soft" style="--brand-dark: {{ BrandPalette::forCompany($company)->dark }}">
                                <x-brand-logo :company="$company" compact />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="mt-[18px] text-center text-[0.8125rem] text-fg-muted">
                Solo para colaboradores del grupo. ¿Problemas para entrar?
                @if ($supportUrl)
                    <a href="{{ $supportUrl }}" target="_blank" rel="noopener" class="font-semibold text-fg underline underline-offset-2">Escribe a soporte de TI</a>.
                @else
                    Escribe a soporte de TI.
                @endif
            </p>
        </section>
    </main>
</x-layouts.app>
