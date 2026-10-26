<span {{ $attributes->class(['flex min-w-0', 'flex-col items-start gap-1' => $logoUrl, 'items-center gap-3' => ! $logoUrl]) }}>
    @if ($logoUrl)
        <span @class([
            'flex h-11 shrink-0 items-center',
            'rounded-control bg-brand-dark px-3' => $onDark && ! $onBand,
            'rounded-full bg-white px-4 ring-1 ring-line' => ! $onDark,
        ])>
            <img src="{{ $logoUrl }}" alt="{{ $name }}" @if ($logoSize) width="{{ $logoSize[0] }}" height="{{ $logoSize[1] }}" @endif class="h-7 w-auto max-w-48 object-contain" decoding="async">
        </span>
        @if ($slot->isNotEmpty())
            <span class="min-w-0 leading-none">{{ $slot }}</span>
        @endif
    @else
        @if ($iconUrl)
            <img src="{{ $iconUrl }}" alt="" width="36" height="36" class="size-9 shrink-0 rounded-[10px] object-contain">
        @else
            <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-brand-button font-display text-lg font-bold text-white">{{ $initial }}</span>
        @endif
        <span class="flex min-w-0 flex-col leading-tight">
            <span class="truncate font-display text-lg font-bold">{{ $name }}</span>
            {{ $slot }}
        </span>
    @endif
</span>
