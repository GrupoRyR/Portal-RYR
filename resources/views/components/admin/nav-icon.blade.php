@props(['name'])
{{-- 18px stroke icons from the approved admin design. --}}
<svg {{ $attributes->merge(['class' => 'size-[18px] shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('apps')
            <rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>
            @break
        @case('tag')
            <path d="M4 12V5a1 1 0 0 1 1-1h7l8 8-8 8z"/><circle cx="8.5" cy="8.5" r="1.2"/>
            @break
        @case('megaphone')
            <path d="M4 10v4a1 1 0 0 0 1 1h2l6 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M17 9a4 4 0 0 1 0 6"/>
            @break
        @case('building')
            <path d="M4 20V6a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v14M15 10h3a2 2 0 0 1 2 2v8M3 20h18M8 8h3M8 12h3M8 16h3"/>
            @break
        @case('user-check')
            <circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 11l2 2 4-4"/>
            @break
        @case('plug')
            <path d="M9 4v5M15 4v5M7 9h10v3a5 5 0 0 1-10 0zM12 17v3"/>
            @break
        @case('list')
            <path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>
            @break
        @case('chart')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>
            @break
        @case('gear')
            <circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7 7 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2L10 21h4l.5-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z"/>
            @break
        @case('back')
            <path d="M15 6l-6 6 6 6"/>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"/>
            @break
        @case('close')
            <path d="M6 6l12 12M18 6L6 18"/>
            @break
    @endswitch
</svg>
