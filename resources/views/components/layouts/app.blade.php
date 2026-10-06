@props([
    'title' => null,
    'noindex' => false,
    // Livewire + Alpine (~96 kB gzip) load only on pages that need them.
    'livewire' => false,
    // Lets the page draw under the notch and home indicator; it must then
    // pad fixed bars with env(safe-area-inset-*) itself (home bottom bar).
    'viewportFit' => false,
])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1{{ $viewportFit ? ', viewport-fit=cover' : '' }}">
    <meta name="theme-color" content="#F5F5F2" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121315" media="(prefers-color-scheme: dark)">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <x-brand-style />

    @if ($livewire)
        @vite(['resources/css/app.css', 'resources/js/livewire.js'])
        @livewireStyles
    @else
        @vite('resources/css/app.css')
    @endif
    {{ $head ?? '' }}
</head>
<body {{ $attributes->merge(['class' => 'min-h-dvh']) }}>
    {{ $slot }}

    @if ($livewire)
        @livewireScriptConfig
    @endif
</body>
</html>
