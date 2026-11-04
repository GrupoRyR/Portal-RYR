{{--
    «Cambiar de empresa» in the glass header (App\View\Components\AccountSwitcher):
    the current company, marked with aria-current and never a button, and one
    POST form per other company. A segmented switch up to three companies, a
    <details> menu from four. No JavaScript (resources/js/header.js only
    closes the menu).
--}}
@if ($switcher['menu'])
    <details {{ $attributes->class(['relative']) }} data-account-switcher data-menu>
        <summary class="g-btn w-full list-none justify-between gap-3 md:w-auto [&::-webkit-details-marker]:hidden">
            <span class="min-w-0 truncate"><span class="sr-only">Cambiar de empresa. Empresa actual: </span>{{ $switcher['current'] }}</span>
            <span aria-hidden="true">▾</span>
        </summary>
        <div class="g-pop left-0">
            <p class="g-label px-3 pt-2 pb-1">Cambiar de empresa</p>
            <ul role="list" class="flex flex-col gap-0.5">
                <li aria-current="true" class="flex min-h-touch items-center justify-between gap-3 rounded-[14px] bg-(--g-accent-tint) px-3 text-sm font-semibold text-(--g-accent-ink)">
                    <span class="min-w-0 truncate">{{ $switcher['current'] }}</span>
                    <span class="text-xs">Actual</span>
                </li>
                @foreach ($switcher['targets'] as $target)
                    <li>
                        <form method="POST" action="{{ route('account.switch', $target['id']) }}">
                            @csrf
                            <button type="submit" class="flex min-h-touch w-full cursor-pointer items-center rounded-[14px] px-3 text-left text-sm font-semibold hover:bg-(--g-hover-row)">
                                <span class="sr-only">Cambiar a </span><span class="min-w-0 truncate">{{ $target['label'] }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    </details>
@else
    <nav {{ $attributes }} aria-label="Cambiar de empresa" data-account-switcher>
        <ul role="list" class="g-seg">
            <li aria-current="true" class="flex min-h-9 min-w-0 items-center rounded-full bg-(--g-accent-tint) px-3.5 text-sm font-semibold text-(--g-accent-ink)">
                <span class="sr-only">Empresa actual: </span><span class="min-w-0 truncate">{{ $switcher['current'] }}</span>
            </li>
            @foreach ($switcher['targets'] as $target)
                <li class="flex min-w-0">
                    <form method="POST" action="{{ route('account.switch', $target['id']) }}" class="flex">
                        @csrf
                        <button type="submit" class="min-h-9 min-w-0 truncate rounded-full px-3.5 hover:bg-(--g-hover-row)">
                            <span class="sr-only">Cambiar a </span>{{ $target['label'] }}
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
