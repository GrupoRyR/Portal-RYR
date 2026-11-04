{{--
    «Mercados» band under the header (odd/tasks/inicio-bloques.md H1): TRM
    and euro in pesos with their change against the previous value
    (App\View\Home\MarketFigure). Static, no ticker: it wraps on phones.
    The arrow is for the eye; screen readers hear «Subió 12,40 pesos (0,32 %)».
--}}
@props(['figures'])

<section data-markets aria-labelledby="mercados-titulo" {{ $attributes->merge(['class' => 'border-b border-line bg-surface']) }}>
    <div class="mx-auto flex max-w-[1440px] flex-wrap items-baseline gap-x-6 gap-y-1 px-4 py-2.5 md:px-8 lg:px-10">
        <h2 id="mercados-titulo" class="font-sans text-xs font-bold tracking-[0.06em] text-brand-text uppercase">Mercados</h2>
        <ul class="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
            @foreach ($figures as $figure)
                <li data-market="{{ $figure->code }}" class="flex items-baseline gap-1.5 whitespace-nowrap">
                    <span class="text-fg-soft">{{ $figure->label }}</span>
                    <span class="font-bold tabular-nums">{{ $figure->value }}</span>
                    @if ($short = $figure->shortChange())
                        <span aria-hidden="true" class="text-xs font-semibold text-fg-soft tabular-nums">{{ $short }}</span>
                        <span class="sr-only">{{ $figure->spokenChange() }}</span>
                    @endif
                    <span class="text-xs text-fg-muted"><span class="sr-only">vigente el </span><time datetime="{{ $figure->validFromIso }}">{{ $figure->validFromLabel }}</time></span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
