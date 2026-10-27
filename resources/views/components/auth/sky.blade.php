{{--
    Decorative app tiles behind the sign-in card (docs/diseno/LoginOpcionB.html,
    styles in resources/css/app.css under "Sign-in stage"). With motion they
    cross the stage like comets; with reduced motion the still layout shows
    instead. Generic glyphs for now: real app icons come later.
--}}
@php
    $glyphs = [
        'pedidos' => '<rect x="5" y="4.5" width="14" height="16.5" rx="2.5"/><path d="M9.5 3h5v3h-5z"/><path d="M8.5 11h7M8.5 14.5h7M8.5 18h4"/>',
        'portafolio' => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
        'cartera' => '<path d="M4.5 8V7a2 2 0 0 1 2-2h10v3"/><rect x="4" y="8" width="16" height="11" rx="2.5"/><path d="M20 11.5h-3.5a2 2 0 0 0 0 4H20"/>',
        'pagar' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'camaras' => '<rect x="3" y="7" width="12" height="10" rx="2.5"/><path d="M15 10.5l6-3.5v10l-6-3.5"/>',
        'informe' => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M10 16l4.5-4.5M11.5 11.5h3v3"/>',
        'personas' => '<circle cx="9" cy="8.5" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c1.8.8 3 2.6 3 4.7"/>',
        'graficas' => '<path d="M3 20h18"/><path d="M6 20v-7M11 20V5M16 20v-10M21 20v-4"/>',
        'candado' => '<rect x="5" y="10" width="14" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'mas' => '<path d="M12 5v14M5 12h14"/>',
    ];

    // Comets: glyph, top, head size, trail length, period, delay (negative,
    // so some are mid-flight when the page opens), dark head, far, desktop only.
    $comets = [
        ['pedidos', '2%', '96px', '340px', '22s', '-3s', false, false, false],
        ['portafolio', '30%', '112px', '400px', '26s', '-15s', true, false, false],
        ['cartera', '54%', '76px', '280px', '20s', '-9s', false, false, false],
        ['camaras', '-10%', '64px', '240px', '28s', '-21s', false, true, false],
        ['pagar', '68%', '88px', '320px', '24s', '-2s', false, false, false],
        ['graficas', '14%', '56px', '220px', '30s', '-12s', false, true, true],
        ['personas', '42%', '100px', '360px', '25s', '-19s', true, false, true],
        ['informe', '80%', '60px', '230px', '27s', '-6s', false, true, false],
        ['candado', '-2%', '72px', '260px', '23s', '-11s', false, false, false],
        ['mas', '22%', '48px', '180px', '32s', '-25s', false, true, true],
        ['portafolio', '62%', '64px', '240px', '29s', '-17s', false, true, true],
    ];

    // Still layout: glyph, left, top, size, tilt, dark, far, and the phone
    // place (left, top, size) or null when the tile is hidden on phones.
    $floats = [
        ['pedidos', '9%', '14%', '112px', '-8deg', false, false, ['6%', '32px', '88px']],
        ['cartera', '22%', '60%', '88px', '6deg', false, false, null],
        ['camaras', '5%', '72%', '120px', '-4deg', true, false, null],
        ['informe', '27%', '6%', '64px', '10deg', false, true, null],
        ['portafolio', '78%', '10%', '120px', '7deg', true, false, ['70%', '20px', '92px']],
        ['pagar', '86%', '48%', '84px', '-9deg', false, false, null],
        ['graficas', '70%', '74%', '108px', '5deg', false, false, null],
        ['personas', '72%', '36%', '60px', '-12deg', false, true, null],
        ['candado', '14%', '40%', '56px', '14deg', false, true, null],
        ['mas', '90%', '82%', '64px', '-6deg', false, true, null],
    ];
@endphp
<div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
    <ul class="signin-comets absolute inset-0">
        @foreach ($comets as [$glyph, $top, $size, $tail, $period, $delay, $dark, $far, $desktopOnly])
            <li @class(['signin-comet', 'signin-comet-far' => $far, 'signin-desk' => $desktopOnly]) style="--y: {{ $top }}; --size: {{ $size }}; --tail: {{ $tail }}; --d: {{ $period }}; --delay: {{ $delay }}; --r: {{ $dark ? '-6deg' : '8deg' }}">
                <span class="signin-comet-tail"></span>
                <span @class(['signin-tile', 'signin-tile-dark' => $dark])><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $glyphs[$glyph] !!}</svg></span>
            </li>
        @endforeach
    </ul>

    <ul class="signin-floats absolute inset-0">
        @foreach ($floats as [$glyph, $left, $top, $size, $tilt, $dark, $far, $phone])
            <li @class(['signin-tile signin-float', 'signin-tile-dark' => $dark, 'signin-float-far' => $far, 'signin-desk' => $phone === null]) style="--x: {{ $left }}; --y: {{ $top }}; --s: {{ $size }}; --r: {{ $tilt }}@if ($phone); --phone-x: {{ $phone[0] }}; --phone-y: {{ $phone[1] }}; --phone-s: {{ $phone[2] }}@endif"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $glyphs[$glyph] !!}</svg></li>
        @endforeach
        <li class="signin-tile signin-float signin-phone" style="--x: 44%; --y: 4%; --s: 72px; --r: -5deg; --phone-x: 40%; --phone-y: 64px; --phone-s: 64px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $glyphs['cartera'] !!}</svg></li>
    </ul>
</div>
