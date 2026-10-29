{{--
    One announcement (docs/diseno/Main.dc.html, aside «Anuncios»). Used by
    the home aside, the /anuncios page and the admin preview, so all three
    always look the same. $card is an App\View\Home\AnnouncementCard; its
    text is plain and escaped here, with its line breaks kept by CSS.

    A mandatory one shows «Lectura obligatoria» and, until the viewer
    confirms it, a plain POST form «Confirmar lectura»
    (App\Http\Controllers\AnnouncementReadController); after, «Leído el …».
    $from = 'home' brings the person back to the home after confirming.
--}}
@props(['card', 'heading' => 'h3', 'large' => false, 'from' => null])
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
        @if ($card->requiresRead)
            <span class="inline-flex items-center rounded-md bg-status-maintenance-bg px-2 py-0.5 font-bold text-status-maintenance">Lectura obligatoria</span>
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
    @if ($card->pendingRead())
        <form method="POST" action="{{ route('announcements.read', $card->id) }}" class="pt-1">
            @csrf
            @if ($from)
                <input type="hidden" name="from" value="{{ $from }}">
            @endif
            <button type="submit" class="inline-flex min-h-touch items-center rounded-control bg-brand-button px-4 text-[0.9375rem] font-semibold text-white">Confirmar lectura</button>
        </form>
    @elseif ($card->readOn !== null)
        <p class="inline-flex items-center gap-1.5 pt-1 text-sm font-semibold text-status-active">
            <x-home.icon name="check" stroke-width="2.4" class="size-4" />Leído el {{ $card->readOn }}
        </p>
    @elseif ($card->requiresRead)
        {{-- Admin preview: not saved yet, so nothing to confirm. --}}
        <span class="inline-flex min-h-touch items-center self-start rounded-control bg-brand-button px-4 text-[0.9375rem] font-semibold text-white">Confirmar lectura</span>
    @endif
</article>
