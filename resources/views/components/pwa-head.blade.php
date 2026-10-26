@if ($themeColor)
    {{-- Same color as the header band behind the company logo. --}}
    <meta name="theme-color" content="{{ $themeColor }}">
@else
    <meta name="theme-color" content="{{ \App\Pwa\WebManifest::THEME_COLOR }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#121315" media="(prefers-color-scheme: dark)">
@endif
<link rel="manifest" href="{{ route('pwa.manifest') }}" crossorigin="use-credentials">
<link rel="apple-touch-icon" href="{{ $icons->apple180 }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $title }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
