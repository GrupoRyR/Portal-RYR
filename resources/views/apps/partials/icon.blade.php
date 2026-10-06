{{-- App tile icon: light/dark SVG files, or the initial on the accent color. --}}
@php($class ??= 'size-12')
@php($accent = \App\Branding\HexColor::tryFrom($app->accent_color)?->toHex() ?? '#334155')
@if ($app->icon_path)
    <picture>
        @if ($app->icon_dark_path)
            <source srcset="{{ asset($app->icon_dark_path) }}" media="(prefers-color-scheme: dark)">
        @endif
        <img src="{{ asset($app->icon_path) }}" alt="" width="48" height="48" class="{{ $class }} shrink-0 rounded-icon">
    </picture>
@else
    <span aria-hidden="true" class="{{ $class }} flex shrink-0 items-center justify-center rounded-icon font-display text-xl font-bold text-white" style="background-color: {{ $accent }}">{{ mb_strtoupper(mb_substr($app->name, 0, 1)) }}</span>
@endif
