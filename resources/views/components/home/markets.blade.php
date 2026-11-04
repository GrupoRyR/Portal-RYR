{{--
    «Mercados» band under the header (odd/tasks/liquid-glass.md G5): every
    market code with its value, its change against the previous value
    (App\View\Home\MarketFigure) and the day it is valid for.

    A glass ticker with no JavaScript: the list is printed again
    (App\View\Home\MarketTicker) and the track slides one copy per lap, so
    the loop never jumps. The copies are hidden from screen readers and
    carry no data-market. It pauses on hover and on keyboard focus; under
    reduced motion, or with a single figure, it stands still and wraps
    (glass.css, section 6).

    A rise is green ▲, a fall red ▼, no move a neutral ●. The arrow is for
    the eye; screen readers hear «Subió 12,40 pesos (0,32 %)».
--}}
@props(['figures'])

@php($ticker = \App\View\Home\MarketTicker::for($figures))

<section data-markets aria-labelledby="mercados-titulo" {{ $attributes->merge(['class' => 'g-ticker']) }}>
    <div class="g-ticker-in mx-auto max-w-[1440px] px-4 md:px-8 lg:px-10">
        <h2 id="mercados-titulo" class="g-ticker-tag">Mercados</h2>
        <div
            @class(['g-ticker-view', 'g-ticker-moves' => $ticker->moves])
            @if ($ticker->moves) tabindex="0" role="group" aria-label="Indicadores de mercado; se detienen al pasar el cursor o con el foco" @endif
        >
            <div class="g-ticker-track" data-market-track @if ($ticker->moves) style="--g-ticker-groups: {{ $ticker->groups }}; --g-ticker-duration: {{ $ticker->seconds }}s" @endif>
                @for ($group = 0; $group < $ticker->groups; $group++)
                    <ul class="g-ticker-group" @if ($group > 0) aria-hidden="true" @endif>
                        @foreach ($figures as $figure)
                            <li @if ($group === 0) data-market="{{ $figure->code }}" @endif class="g-ticker-item">
                                <span>{{ $figure->label }}</span>
                                <span class="g-ticker-value">{{ $figure->value }}</span>
                                @if ($short = $figure->shortChange())
                                    <span data-market-change="{{ $figure->direction }}" class="g-ticker-change {{ $figure->tone() }}" aria-hidden="true">{{ $short }}</span>
                                    <span class="sr-only">{{ $figure->spokenChange() }}</span>
                                @endif
                                <span class="g-ticker-date"><span class="sr-only">vigente el </span><time datetime="{{ $figure->validFromIso }}">{{ $figure->validFromLabel }}</time></span>
                            </li>
                        @endforeach
                    </ul>
                @endfor
            </div>
        </div>
    </div>
</section>
