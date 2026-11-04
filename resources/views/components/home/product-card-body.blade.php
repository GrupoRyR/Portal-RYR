{{-- The inside of one product tile of x-home.catalog (an App\View\Home\ProductCard). --}}
@props(['product'])

@if ($product->photoUrl)
    <img src="{{ $product->photoUrl }}" alt="" width="320" height="240" loading="lazy" decoding="async" class="g-prod__art aspect-[4/3] w-full bg-subtle object-cover">
@else
    <span aria-hidden="true" class="g-prod__art flex aspect-[4/3] w-full items-center justify-center bg-brand-tint text-brand-ink">
        <x-home.icon name="grid" stroke-width="1.4" class="size-10" />
    </span>
@endif
<span class="flex min-w-0 flex-col gap-0.5 px-3 pt-2.5 pb-3">
    <span class="text-sm leading-snug font-bold break-words">{{ $product->name }}@if ($product->linkUrl)<span class="sr-only"> (se abre en otra pestaña)</span>@endif</span>
    <span class="text-xs text-fg-muted">{{ $product->categoryName }}</span>
    @if ($product->summary)
        <span class="line-clamp-2 text-xs text-fg-soft">{{ $product->summary }}</span>
    @endif
</span>
