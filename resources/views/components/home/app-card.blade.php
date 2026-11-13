{{--
    One app card of «Mis aplicaciones», a glass tile (glass.css section 7,
    .g-app; docs/diseno/Main.dc.html). The whole card opens the app
    through a stretched link; the star is a separate form so it works
    without JavaScript (resources/js/home.js submits it with fetch).
    The logo container (.app-logo) carries the motion of resources/css/app.css,
    whatever it holds; data-enter asks for the entrance on a plain page load
    and home.js drops it when the card's pop ends. `order` is the card's place
    on the page (home.blade.php); the entrance timing, its cap included, lives
    in app.css alone.

    `view` (App\View\Home\AppsView) picks the layout: the tile of
    «Categorías» and «Todas» (the default), a row of «Lista» (.g-app--row:
    logo, name, description, status, star) or the icon of «Íconos»
    (.g-app--icon: logo and name, the star in a corner, the status only when
    the app is down or in maintenance). Logo, status and star come from
    x-home.app-logo, x-home.app-status and x-home.app-star in all three.
--}}
@props(['card', 'query' => '', 'order' => 0, 'view' => null])
@use('App\View\Home\AppCardStatus')
@use('App\View\Home\AppsView')
@php
    $layout = match ($view) {
        AppsView::List => 'row',
        AppsView::Launcher => 'icon',
        default => 'tile',
    };
    $link = "after:absolute after:inset-0 after:rounded-[18px] after:content-[''] focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-fg";
@endphp
<li
    id="app-{{ $card->slug }}"
    data-app="{{ $card->slug }}"
    data-category="{{ $card->categorySlug }}"
    data-position="{{ $card->position }}"
    data-search="{{ $card->searchText }}"
    data-status="{{ $card->status->value }}"
    @if ($query === '') data-enter style="--logo-order: {{ $order }}" @endif
    @class([
        'g-tile g-app min-w-0',
        'g-app--row' => $layout === 'row',
        'g-app--icon' => $layout === 'icon',
        'g-app--failing' => $card->status === AppCardStatus::Failing,
    ])
>
    @if ($layout === 'row')
        <x-home.app-logo :card="$card" small />

        <div class="g-app__text">
            <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="truncate text-[0.9375rem] leading-tight font-bold {{ $link }}">{{ $card->name }}</a>
            <p class="truncate text-[0.8125rem] leading-snug text-fg-muted">{{ $card->description }}</p>
        </div>

        <div class="g-app__status">
            <x-home.app-status :card="$card" />
        </div>

        <x-home.app-star :card="$card" :query="$query" class="g-app__star relative z-10" />
    @elseif ($layout === 'icon')
        <x-home.app-logo :card="$card" />

        <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="line-clamp-2 text-[0.8125rem] leading-tight font-semibold break-words {{ $link }}">{{ $card->name }}</a>

        @if (in_array($card->status, [AppCardStatus::Maintenance, AppCardStatus::Failing], true))
            <div class="g-app__status">
                <x-home.app-status :card="$card" />
            </div>
        @endif

        <x-home.app-star :card="$card" :query="$query" class="g-app__star z-10" compact />
    @else
        <div class="flex items-start justify-between">
            <x-home.app-logo :card="$card" />

            <x-home.app-star :card="$card" :query="$query" class="relative z-10 -mt-2 -mr-2" />
        </div>

        <div class="flex flex-col gap-1">
            <a href="{{ $card->href }}"@if ($card->opensInNewTab) target="_blank" rel="noopener"@endif class="text-[0.9375rem] leading-tight font-bold break-words {{ $link }}">{{ $card->name }}</a>
            <p class="line-clamp-2 text-[0.8125rem] leading-snug text-fg-muted">{{ $card->description }}</p>
        </div>

        <x-home.app-status :card="$card" />
    @endif
</li>
