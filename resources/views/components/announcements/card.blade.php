{{--
    One announcement (docs/diseno/Main.dc.html, aside «Anuncios»). Used by
    the home aside, the /anuncios page and the admin preview, so all three
    always look the same. $card is an App\View\Home\AnnouncementCard; its
    text is plain and escaped here, with its line breaks kept by CSS.
--}}
@props(['card', 'heading' => 'h3', 'large' => false])
<article data-announcement-card {{ $attributes->merge(['class' => 'flex flex-col gap-2.5']) }}>
    @if ($card->imageUrl)
        <img src="{{ $card->imageUrl }}" alt="" width="1280" height="720" loading="lazy" decoding="async"
             class="aspect-video w-full rounded-[12px] bg-brand-tint object-cover">
    @endif
    <p class="flex flex-wrap items-center gap-2 text-xs text-fg-muted">
        @if ($card->pinned)
            <span class="inline-flex items-center gap-1 rounded-md bg-brand-tint px-2 py-0.5 font-bold text-brand-ink">
                <x-home.icon name="pin" stroke-width="2.4" class="size-[11px]" />Fijado
            </span>
        @endif
        <span>{{ $card->date }}</span>
    </p>
    <{{ $heading }} @class([
        'font-sans leading-snug font-bold tracking-normal break-words',
        'text-base' => ! $large,
        'text-lg md:text-xl' => $large,
    ])>{{ $card->title }}</{{ $heading }}>
    <p @class([
        'leading-normal break-words whitespace-pre-line text-fg-soft',
        'text-sm' => ! $large,
        'text-[0.9375rem]' => $large,
    ])>{{ $card->body }}</p>
</article>
