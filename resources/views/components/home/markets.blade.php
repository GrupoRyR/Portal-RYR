{{--
    «Mercados» band under the header (odd/tasks/liquid-glass.md G5): every
    market code with its value, its change against the previous value
    (App\View\Home\MarketFigure) and the day it is valid for.

    A glass ticker with no JavaScript: the list is printed again
    (App\View\Home\MarketTicker) and the track slides one copy per lap, so
    the loop never jumps. The copies are hidden from screen readers and
    carry no data-market. The edge fade and the clipping sit on an inner
    window, so the focus ring of the view is never masked. It pauses on
    hover, on keyboard focus and with
    the «Pausar» control; under reduced motion, or with a single figure, it
    stands still and wraps, without the control (glass.css, section 6).

    A rise is green ▲, a fall red ▼, no move a neutral ●. The arrow is for
    the eye; screen readers hear «Subió 12,40 pesos (0,32 %)», in the
    unit of each code (App\Markets\MarketSeries). A daily figure shows
    its day, a monthly one its month («sep 2026»).
--}}
@props(['figures'])

@php($ticker = \App\View\Home\MarketTicker::for($figures))

<section data-markets aria-labelledby="mercados-titulo" {{ $attributes->merge(['class' => 'g-ticker']) }}>
    <div class="g-ticker-in mx-auto max-w-[1440px] px-4 md:px-8 lg:px-10">
        <h2 id="mercados-titulo" class="g-ticker-tag">Mercados</h2>
        <div
            @class(['g-ticker-view', 'g-ticker-moves' => $ticker->moves])
            @if ($ticker->moves) tabindex="0" role="group" aria-label="Indicadores de mercado" @endif
        >
            <div class="g-ticker-window">
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
                                    <span class="g-ticker-date"><span class="sr-only">{{ $figure->datePrefix() }} </span><time datetime="{{ $figure->validFromIso }}">{{ $figure->validFromLabel }}</time></span>
                                </li>
                            @endforeach
                        </ul>
                    @endfor
                </div>
            </div>
        </div>
        @if ($ticker->moves)
            {{-- Pause for touch screens (WCAG 2.2.2), no JavaScript: the
                 checkbox stops the track through :has(). Its name holds both
                 words the label shows, so voice control finds either. --}}
            <label class="g-ticker-pause">
                <input type="checkbox" class="g-ticker-pause-input" data-market-pause aria-label="Pausar o reanudar los indicadores de mercado">
                <span class="g-ticker-pause-off" aria-hidden="true">Pausar</span>
                <span class="g-ticker-pause-on" aria-hidden="true">Reanudar</span>
            </label>
        @endif
    </div>
</section>
