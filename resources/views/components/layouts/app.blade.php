@props([
    'title' => null,
    'noindex' => false,
    // Livewire + Alpine (~96 kB gzip) load only on pages that need them.
    'livewire' => false,
    // Lets the page draw under the notch and home indicator; it must then
    // pad fixed bars with env(safe-area-inset-*) itself (home bottom bar).
    'viewportFit' => false,
    // The page opens with the --brand-dark band (.brand-band) behind the
    // company logo: the browser bar takes the same color and runs into it.
    'brandBand' => false,
    // Liquid Glass page: the company photo with its readability veil behind
    // everything (.g-canvas, resources/css/glass.css). Text on it must sit
    // inside a glass panel, never straight on the photo.
    'glass' => false,
    // The floating button: the agent's (components/agent-panel) while the
    // connection is configured and the account is one the agent accepts,
    // else the old «Asistente» (components/assistant-fab). Only on the pages
    // that ask for it (home and the secondary pages with the portal header),
    // never on the sign-in, the error pages or Administración, glass or not.
    // Each component checks the account and /asistente itself.
    'assistant' => false,
])
@php
    $user = auth()->user();
    // Only a signed-in account's company brands the page (glass.css keys its
    // values on it); the sign-in and accounts without a company are neutral.
    $brand = ($user instanceof \App\Models\User ? $user->company?->slug : null) ?: 'neutral';
@endphp
<!DOCTYPE html>
<html lang="es" data-brand="{{ $brand }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1{{ $viewportFit ? ', viewport-fit=cover' : '' }}">
    {{-- Before any stylesheet, so the first paint already has the theme the
         person chose ("g-theme": light or dark). Without a choice there is
         no attribute and the system decides; storage may be blocked. --}}
    <script>(function(){try{var t=localStorage.getItem('g-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t)}catch(e){}})()</script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <x-brand-style />
    <x-pwa-head :brand-band="$brandBand" />

    {{-- header.js: the theme choice and the header menus (a few hundred bytes). --}}
    @if ($livewire)
        @vite(['resources/css/app.css', 'resources/js/header.js', 'resources/js/livewire.js'])
        @livewireStyles
    @else
        @vite(['resources/css/app.css', 'resources/js/header.js'])
    @endif
    {{ $head ?? '' }}
</head>
<body {{ $attributes->class(['min-h-dvh', 'g-canvas' => $glass]) }}>
    {{ $slot }}

    @if ($assistant)
        {{-- The agent's button for an account the agent accepts once the
             connection is configured (valid origin and key); the old
             «Asistente» button for every other case, so no account is left
             without one. --}}
        @if (\App\View\Components\AgentPanel::offeredTo($user))
            <x-agent-panel />
        @else
            <x-assistant-fab />
        @endif
    @endif

    @if ($livewire)
        @livewireScriptConfig
    @endif
</body>
</html>
