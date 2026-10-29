{{--
    Top band of a secondary page of a signed-in user (brand manual SGX/SDC):
    the company logo on --brand-dark, linking back to the home screen, and
    «Cambiar de empresa» for linked accounts (App\View\Components\AccountSwitcher).
--}}
<header class="brand-band">
    <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-3 px-4 py-2 md:px-8 lg:px-10">
        <a href="{{ route('home') }}" class="flex min-h-touch items-center rounded-control" aria-label="Ir al inicio">
            <x-brand-logo on-band />
        </a>
        <x-account-switcher class="min-w-0" />
    </div>
</header>
