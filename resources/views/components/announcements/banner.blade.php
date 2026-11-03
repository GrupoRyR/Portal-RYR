{{--
    The top announcement as a tinted strip on phone and tablet
    (docs/diseno/PhoneHome.dc.html, Tablet.dc.html). Links to the
    announcement on /anuncios; the admin preview (no id yet) gets no link.
    Sized by its container, not the viewport, so the admin «Celular»
    preview looks like a phone on any screen. A mandatory announcement not
    read yet says «Lectura obligatoria»; the button is on /anuncios (a link
    cannot hold a form). $card is an
    App\View\Home\AnnouncementCard.
--}}
@props(['card'])
@php
    $href = $card->href();
    $tag = $href ? 'a' : 'div';
@endphp
<div {{ $attributes->merge(['class' => '@container']) }}>
    <{{ $tag }} @if ($href) href="{{ $href }}" @endif data-announcement-strip
        class="flex items-center gap-3 rounded-[16px] bg-brand-tint p-3.5 text-brand-ink @md:gap-4 @md:rounded-card @md:px-5 @md:py-4">
        <span class="flex shrink-0 items-center justify-center @md:size-12 @md:rounded-icon @md:bg-surface">
            <x-home.icon name="megaphone" class="size-[22px] @md:size-6" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5 @md:gap-1">
            <span class="text-[0.9375rem] leading-snug font-bold break-words @md:text-[1.0625rem]">{{ $card->title }}</span>
            <span class="hidden text-sm @md:line-clamp-1">{{ $card->body }}</span>
            <span class="text-[0.8125rem] @md:text-sm">{{ $card->pinned ? 'Anuncio fijado · ' : '' }}@if ($card->requiresRead && $card->readOn === null)<span class="font-bold">Lectura obligatoria</span> · @endif{{ $card->date }}</span>
        </span>
        @if ($href)
            <x-home.icon name="chevron-right" stroke-width="2" class="size-[18px] @md:size-5" />
        @endif
    </{{ $tag }}>
</div>
