{{--
    The logo of an app card (x-home.app-card): the uploaded icon, with its
    dark variant, or the initial on the app's accent color. `.app-logo`
    carries the motion of resources/css/app.css. `small` is the 40 px logo
    of «Lista»; the others are 48 px.
--}}
@props(['card', 'small' => false])
@if ($card->iconUrl)
    <picture class="app-logo block shrink-0">
        @if ($card->iconDarkUrl)
            <source srcset="{{ $card->iconDarkUrl }}" media="(prefers-color-scheme: dark)">
        @endif
        <img src="{{ $card->iconUrl }}" alt="" width="{{ $small ? 40 : 48 }}" height="{{ $small ? 40 : 48 }}" class="{{ $small ? 'size-10' : 'size-12' }} rounded-icon">
    </picture>
@else
    <span aria-hidden="true" class="app-logo flex {{ $small ? 'size-10' : 'size-12' }} shrink-0 items-center justify-center rounded-icon bg-(--app-accent) font-display {{ $small ? 'text-lg' : 'text-xl' }} font-bold text-white" style="--app-accent: {{ $card->accentColor }}">{{ $card->initial }}</span>
@endif
