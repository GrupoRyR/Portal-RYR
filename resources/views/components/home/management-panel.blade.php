{{--
    «Panel gerencial» («Solo Gerencia», odd/tasks/inicio-bloques.md H2):
    the month's key figures of the user's own company, published each night
    by the SAP robot (App\View\Home\ManagementCard). HomePageBuilder only
    builds it for a «Gerencia» account of that company. The first figure
    is the large one; a figure with a goal gets a meter. A glass card of the
    main column (glass.css section 7). data-offline-omit: the service worker
    (resources/js/sw.js) never saves it for offline use, so it holds no
    nested <section>: sw.js cuts it at its first closing tag.
--}}
@props(['card'])

<section id="panel-gerencial" aria-labelledby="panel-gerencial-titulo" data-offline-omit {{ $attributes->merge(['class' => 'g-card min-w-0 scroll-mt-6']) }}>
    <div class="g-card__head">
        <span class="g-mark" aria-hidden="true"></span>
        <h2 id="panel-gerencial-titulo">Panel gerencial</h2>
        <span class="g-note"><time datetime="{{ $card->asOfIso }}">{{ $card->closing }}</time></span>
        <span class="g-pill ml-auto normal-case">Solo Gerencia</span>
    </div>

    <div class="g-card__body flex flex-col gap-4">
        <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($card->figures as $figure)
                <div class="flex min-w-0 flex-col gap-1.5">
                    <dt class="text-[0.8125rem] text-fg-muted">{{ $figure->label }}</dt>
                    <dd @class(['font-bold leading-tight tabular-nums break-words', 'text-[2rem] tracking-[-0.02em]' => $loop->first, 'text-2xl' => ! $loop->first])>
                        {{ $figure->value }}@if ($figure->unit) <span class="text-[0.9375rem] font-bold text-fg-muted">{{ $figure->unit }}</span>@endif
                    </dd>
                    @if (($meter = $figure->meter()) !== null)
                        <dd class="flex flex-col gap-1">
                            <div role="progressbar" aria-label="{{ $figure->label }} frente a la meta" aria-valuenow="{{ $meter }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $figure->percentOfGoal }} % de la meta" class="g-bar">
                                <i style="width: {{ $meter }}%"></i>
                            </div>
                            <p class="flex flex-wrap justify-between gap-x-3 text-xs text-fg-muted"><span class="font-semibold text-fg-soft">{{ $figure->percentOfGoal }} % de la meta</span><span>{{ $figure->goal }}</span></p>
                        </dd>
                    @endif
                    @if ($figure->note)
                        <dd class="text-xs text-fg-muted">{{ $figure->note }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>

        <p class="text-xs text-fg-muted">{{ $card->source }}</p>
    </div>
</section>
