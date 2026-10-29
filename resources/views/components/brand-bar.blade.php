{{--
    Top band of a secondary page of a signed-in user (brand manual SGX/SDC):
    the company logo on --brand-dark, linking back to the home screen, and
    «Cambiar de empresa» for linked accounts (App\View\Components\AccountSwitcher).
--}}
<header class="brand-band">
    <div class="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-3 px-4 py-2 md:flex-nowrap md:px-8 lg:px-10">
        <a href="{{ route('home') }}" class="flex min-h-touch min-w-0 items-center rounded-control" aria-label="Ir al inicio">
            <x-brand-logo on-band />
        </a>
        {{-- Its own row on phones. --}}
        <x-account-switcher class="min-w-0 basis-full md:basis-auto" />
    </div>
</header>
