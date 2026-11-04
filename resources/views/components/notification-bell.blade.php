{{--
    The bell of components/portal-header (odd/tasks/liquid-glass.md G4,
    approved sketch of 2026-11-04): a <details> menu with a numeric badge
    and a glass list of what needs the person (App\View\Notifications\NotificationList).
    Rendered by the server, no polling; it works without JavaScript and
    resources/js/header.js closes it on a click elsewhere or Escape
    (data-menu). Titles come from stored data: always escaped ({{ }}).
    On phones the list spans the header (the <details> is not positioned
    there); from tablets up it hangs from the bell.
--}}
@props(['notifications'])
@php
    /** @var \App\View\Notifications\NotificationList $notifications */
    use App\View\Notifications\Notification;

    $icons = [
        Notification::ANNOUNCEMENT => 'megaphone',
        Notification::APPROVAL => 'check',
        Notification::TASK => 'pin',
        Notification::EVENT => 'calendar',
    ];
    $row = 'flex min-h-touch items-start gap-3 rounded-[14px] px-3 py-2.5 text-sm hover:bg-(--g-hover-row)';
    $badge = $notifications->badge();
@endphp
<details class="sm:relative" data-bell data-menu>
    <summary class="g-btn relative size-touch list-none p-0 [&::-webkit-details-marker]:hidden">
        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
        <span class="sr-only">{{ $notifications->label() }}</span>
        @if ($badge !== null)
            <span aria-hidden="true" class="absolute -top-0.5 -right-0.5 h-[18px] min-w-[18px] rounded-full bg-(--g-button) px-[5px] text-center text-[11px] leading-[18px] font-bold text-white" data-bell-badge>{{ $badge }}</span>
        @endif
    </summary>
    <div class="g-pop inset-x-3 w-auto sm:right-0 sm:left-auto sm:w-[22rem]">
        <p id="avisos-titulo" class="g-label px-3 pt-2 pb-1">Avisos</p>
        @if ($notifications->isEmpty())
            <p class="px-3 pt-1 pb-3 text-sm text-(--g-ink-soft)" data-bell-empty>No tienes avisos nuevos</p>
        @else
            <ul role="list" aria-labelledby="avisos-titulo" class="flex flex-col gap-0.5">
                @foreach ($notifications->items as $item)
                    <li data-bell-item="{{ $item->kind }}">
                        <a href="{{ $item->href }}" class="{{ $row }}" @if ($item->external) target="_blank" rel="noopener" @endif>
                            <x-home.icon :name="$icons[$item->kind]" class="mt-0.5 size-[18px] text-(--g-accent-ink)" />
                            <span class="flex min-w-0 flex-col">
                                <span class="line-clamp-2 font-semibold text-(--g-ink)" data-bell-title>{{ $item->title }}</span>
                                @if ($item->detail)
                                    <span class="text-xs text-(--g-ink-faint)">{{ $item->detail }}</span>
                                @endif
                                @if ($item->external)
                                    <span class="sr-only">(se abre en otra pestaña)</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="border-t border-(--g-line) pt-1">
                <a href="{{ route('home') }}#mi-dia" class="flex min-h-touch items-center justify-center rounded-[14px] px-3 text-sm font-semibold text-(--g-accent-ink) hover:bg-(--g-hover-row)" data-bell-all>Ver todo</a>
            </div>
        @endif
    </div>
</details>
