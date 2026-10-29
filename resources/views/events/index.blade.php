{{--
    «Eventos»: every upcoming event the user may see, soonest first, each
    with «Agregar a mi calendario» (an .ics file, App\Calendar\EventCalendarFile).
    $events is a list of App\View\Home\EventDate. No Livewire or Alpine;
    each item has an anchor (#e-{id}) for «Próximas fechas» and the search.
--}}
<x-layouts.app title="Eventos" brand-band>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-brand-bar />

    <main id="contenido" class="mx-auto flex max-w-[680px] flex-col gap-6 px-4 pt-4 pb-12 md:px-8 md:pt-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('home') }}" class="-ml-1 inline-flex min-h-touch items-center gap-1.5 self-start pr-2 text-sm font-semibold text-fg-soft underline-offset-4 hover:underline">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1 class="text-[1.75rem] leading-tight font-bold md:text-[2.125rem]">Eventos</h1>
        </div>

        @if ($events === [])
            <p class="rounded-card border border-line bg-surface px-5 py-8 text-center text-[0.9375rem] text-fg-muted">No hay eventos próximos.</p>
        @else
            <ol class="flex flex-col gap-4">
                @foreach ($events as $event)
                    <li id="e-{{ $event->id }}" class="scroll-mt-6 rounded-card border border-line bg-surface p-5 target:border-brand-button md:p-6">
                        <article class="flex gap-4">
                            <time datetime="{{ $event->isoDate() }}" aria-hidden="true" class="flex w-14 shrink-0 flex-col items-center self-start rounded-[10px] bg-brand-tint py-2 leading-tight text-brand-ink">
                                <span class="text-xl font-bold">{{ $event->dayNumber() }}</span>
                                <span class="text-xs">{{ $event->shortMonth() }}</span>
                            </time>
                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                <h2 id="event-{{ $event->id }}-title" class="font-sans text-lg leading-snug font-bold tracking-normal break-words md:text-xl">{{ $event->title }}</h2>
                                <p class="text-sm font-semibold text-fg-soft">{{ $event->when() }}</p>
                                @if ($event->location)
                                    <p class="text-sm break-words text-fg-muted"><span class="sr-only">Lugar: </span>{{ $event->location }}</p>
                                @endif
                                @if ($event->description)
                                    <p class="text-[0.9375rem] leading-normal break-words whitespace-pre-line text-fg-soft">{{ $event->description }}</p>
                                @endif
                                <a href="{{ $event->calendarHref() }}" download aria-describedby="event-{{ $event->id }}-title"
                                   class="mt-1 inline-flex min-h-touch items-center gap-2 self-start rounded-control bg-brand-button px-4 text-[0.9375rem] font-semibold text-white">
                                    <x-home.icon name="calendar" stroke-width="2" class="size-[18px]" />Agregar a mi calendario
                                </a>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
    </main>
</x-layouts.app>
