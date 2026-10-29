{{--
    «Cambiar de empresa» on the --brand-dark band (App\View\Components\AccountSwitcher):
    the current company, marked with aria-current and never a button, and one
    POST form per other company. Buttons up to three companies, a <details>
    menu from four. No JavaScript; the band sets white text and focus rings.
--}}
@if ($switcher['menu'])
    <details {{ $attributes->class(['relative']) }} data-account-switcher>
        <summary class="flex min-h-touch w-full cursor-pointer list-none items-center justify-between gap-3 rounded-control border border-white/40 px-4 text-sm font-semibold hover:bg-white/10 md:w-auto [&::-webkit-details-marker]:hidden">
            <span class="min-w-0 truncate"><span class="sr-only">Cambiar de empresa. Empresa actual: </span>{{ $switcher['current'] }}</span>
            <span aria-hidden="true">▾</span>
        </summary>
        <div class="absolute top-full right-0 z-40 mt-2 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-card border border-line bg-surface p-2 text-fg shadow-card [&_:focus-visible]:outline-fg">
            <p class="px-3 pt-2 pb-1 text-xs font-semibold text-fg-muted">Cambiar de empresa</p>
            <ul role="list" class="flex flex-col gap-1">
                <li aria-current="true" class="flex min-h-touch items-center justify-between gap-3 rounded-control bg-brand-tint px-3 text-sm font-semibold text-brand-ink">
                    <span class="min-w-0 truncate">{{ $switcher['current'] }}</span>
                    <span class="text-xs">Actual</span>
                </li>
                @foreach ($switcher['targets'] as $target)
                    <li>
                        <form method="POST" action="{{ route('account.switch', $target['id']) }}">
                            @csrf
                            <button type="submit" class="flex min-h-touch w-full cursor-pointer items-center rounded-control px-3 text-left text-sm font-semibold hover:bg-subtle">
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
        <ul role="list" class="flex gap-1 rounded-control border border-white/40 p-1">
            <li aria-current="true" class="flex min-h-touch min-w-0 flex-1 items-center justify-center rounded-[10px] bg-brand-tint px-3 text-sm font-semibold text-brand-ink">
                <span class="sr-only">Empresa actual: </span><span class="min-w-0 truncate">{{ $switcher['current'] }}</span>
            </li>
            @foreach ($switcher['targets'] as $target)
                <li class="flex min-w-0 flex-1">
                    <form method="POST" action="{{ route('account.switch', $target['id']) }}" class="flex w-full">
                        @csrf
                        <button type="submit" class="flex min-h-touch w-full min-w-0 cursor-pointer items-center justify-center rounded-[10px] px-3 text-sm font-semibold hover:bg-white/10">
                            <span class="sr-only">Cambiar a </span><span class="min-w-0 truncate">{{ $target['label'] }}</span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
