{{--
    «Panel gerencial» («Solo Gerencia», odd/tasks/inicio-bloques.md H2):
    the month's key figures of the user's own company, published each night
    by the SAP robot (App\View\Home\ManagementCard). HomePageBuilder only
    builds it for a «Gerencia» account of that company. The first figure
    is the large one; a figure with a goal gets a meter. data-offline-omit:
    the service worker (resources/js/sw.js) never saves it for offline use.
--}}
@props(['card'])

<section id="panel-gerencial" aria-labelledby="panel-gerencial-titulo" data-offline-omit {{ $attributes->merge(['class' => 'flex scroll-mt-6 flex-col gap-4 rounded-card border border-line bg-surface p-5 md:p-6']) }}>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 id="panel-gerencial-titulo" class="font-sans text-base font-bold tracking-normal text-brand-text">Panel gerencial</h2>
        <span class="text-sm text-fg-muted"><time datetime="{{ $card->asOfIso }}">{{ $card->closing }}</time></span>
        <span class="rounded-full bg-brand-tint px-2.5 py-0.5 text-xs font-bold text-brand-ink">Solo Gerencia</span>
    </div>

    <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($card->figures as $figure)
            <div class="flex min-w-0 flex-col gap-1.5">
                <dt class="text-[0.8125rem] text-fg-muted">{{ $figure->label }}</dt>
                <dd @class(['font-bold leading-tight tabular-nums break-words', 'text-[2rem] tracking-[-0.02em]' => $loop->first, 'text-2xl' => ! $loop->first])>
                    {{ $figure->value }}@if ($figure->unit)<span class="ml-1 text-[0.9375rem] font-bold text-fg-muted">{{ $figure->unit }}</span>@endif
                </dd>
                @if (($meter = $figure->meter()) !== null)
                    <dd class="flex flex-col gap-1">
                        <div role="progressbar" aria-label="{{ $figure->label }} frente a la meta" aria-valuenow="{{ $meter }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $figure->percentOfGoal }} % de la meta" class="h-2 overflow-hidden rounded-full bg-subtle">
                            <div class="h-full rounded-full bg-brand-accent" style="width: {{ $meter }}%"></div>
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
</section>
