{{--
    «Eventos»: every upcoming event the user may see, soonest first, each
    with «Agregar a mi calendario» (an .ics file, App\Calendar\EventCalendarFile).
    $events is a list of App\View\Home\EventDate; $cut says more were left
    out (App\Http\Controllers\EventController::LIMIT). No Livewire or Alpine;
    each item has an anchor (#e-{id}) for «Próximas fechas» and the search.
    Liquid Glass (odd/tasks/liquid-glass.md G9): the list is rows of one
    g-card (up to LIMIT rows, so never a blurred card per event).
--}}
<x-layouts.app title="Eventos" glass assistant>
    <a href="#contenido" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold">Saltar al contenido</a>

    <x-portal-header />

    <main id="contenido" class="g-page">
        <div class="g-banner g-page__hello">
            <a href="{{ route('home') }}" class="g-page__back">
                <x-home.icon name="chevron-left" stroke-width="2" class="size-[18px]" />Volver al inicio
            </a>
            <h1>Eventos</h1>
        </div>

        @if ($events === [])
            <p class="g-card g-page__empty">No hay eventos próximos.</p>
        @else
            <div class="g-card">
                <ol class="g-page__list">
                    @foreach ($events as $event)
                        <li id="e-{{ $event->id }}" class="g-page__entry">
                            <article class="g-page__event">
                                <time datetime="{{ $event->dayIsoDate() }}" aria-hidden="true" class="g-page__date">
                                    <span>{{ $event->dayNumber() }}</span>
                                    <span>{{ $event->shortMonth() }}</span>
                                </time>
                                <div class="flex min-w-0 flex-1 flex-col gap-2">
                                    <h2 id="event-{{ $event->id }}-title" class="g-page__entry-title">{{ $event->title }}</h2>
                                    <p class="g-page__strong text-sm text-(--g-ink-soft)">
                                        {{ $event->listedWhen() }}
                                        @if ($event->repeatsYearly)
                                            <span class="g-pill ml-1 align-[1px]">Cada año</span>
                                        @endif
                                    </p>
                                    @if ($event->location)
                                        <p class="g-page__text text-sm break-words text-(--g-ink-faint)"><span class="sr-only">Lugar: </span>{{ $event->location }}</p>
                                    @endif
                                    @if ($event->description)
                                        <p class="g-page__text break-words whitespace-pre-line">{{ $event->description }}</p>
                                    @endif
                                    <a href="{{ $event->calendarHref() }}" download aria-describedby="event-{{ $event->id }}-title" class="g-btn g-btn--primary g-btn--small mt-1 self-start">
                                        <x-home.icon name="calendar" stroke-width="2" class="size-[18px]" />Agregar a mi calendario
                                    </a>
                                </div>
                            </article>
                        </li>
                    @endforeach
                </ol>

                @if ($cut)
                    <p class="g-page__foot justify-center font-normal">Se muestran los {{ count($events) }} eventos más próximos.</p>
                @endif
            </div>
        @endif
    </main>
</x-layouts.app>
