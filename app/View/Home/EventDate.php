<?php

namespace App\View\Home;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One company event as «Próximas fechas» of the home screen, the /eventos
 * page and the «Ctrl K» search print it. Dates are in the app timezone
 * (Bogotá); times are 24-hour, as elsewhere in the portal.
 */
final readonly class EventDate
{
    /**
     * @param  CarbonImmutable  $start  Start, local; midnight for an all-day event.
     * @param  CarbonImmutable|null  $end  End of a timed event, local.
     * @param  CarbonImmutable  $lastDay  Midnight of its last day, local.
     * @param  CarbonImmutable  $over  When it is over (Event::endsAtMoment()).
     * @param  bool  $isToday  It starts today.
     * @param  int  $year  This year, local: other years are spelled out.
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public ?string $location,
        public bool $allDay,
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public CarbonImmutable $lastDay,
        public CarbonImmutable $over,
        public bool $isToday,
        public int $year,
    ) {}

    /**
     * @param  CarbonImmutable  $today  Midnight today in the app timezone.
     */
    public static function from(Event $event, CarbonImmutable $today): self
    {
        $timezone = config('app.timezone');
        $start = $event->starts_at->toImmutable()->setTimezone($timezone);

        return new self(
            id: (int) $event->id,
            title: (string) $event->title,
            description: self::blankToNull($event->description),
            location: self::blankToNull($event->location),
            allDay: (bool) $event->all_day,
            start: $start,
            end: $event->all_day ? null : $event->ends_at?->toImmutable()->setTimezone($timezone),
            lastDay: $event->lastDay(),
            over: $event->endsAtMoment(),
            isToday: $start->toDateString() === $today->toDateString(),
            year: $today->year,
        );
    }

    /**
     * Holidays and events in one list: by start, a holiday before the
     * events of its day, then by id.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function sortKey(): array
    {
        return [$this->start->getTimestamp(), 1, $this->id];
    }

    public function isMultiDay(): bool
    {
        return $this->lastDay->toDateString() !== $this->start->toDateString();
    }

    public function dayNumber(): string
    {
        return (string) $this->start->day;
    }

    /**
     * «nov», without Carbon's trailing dot.
     */
    public function shortMonth(): string
    {
        return rtrim($this->spanish($this->start)->isoFormat('MMM'), '.');
    }

    /**
     * «Jueves», or «Hoy» when it starts today.
     */
    public function weekday(): string
    {
        return $this->isToday ? 'Hoy' : $this->dayName();
    }

    /**
     * «Jueves», even today: what the saved copy of the page shows once the
     * day is over (resources/js/home.js).
     */
    public function dayName(): string
    {
        return Str::ucfirst($this->spanish($this->start)->isoFormat('dddd'));
    }

    /** The day it starts, for `data-event-date` and `<time>`. */
    public function isoDate(): string
    {
        return $this->start->toDateString();
    }

    /** Its last day: the search hides it from the next day on. */
    public function lastIsoDate(): string
    {
        return $this->lastDay->toDateString();
    }

    /** When it is over, for `data-event-end` (home.js hides it after). */
    public function overIso(): string
    {
        return $this->over->toIso8601String();
    }

    /**
     * «jueves 5 de noviembre», with the year when it is not this one.
     */
    public function longDate(): string
    {
        return $this->day($this->start);
    }

    /**
     * The middle part of the «Próximas fechas» line: the start time, or
     * «Hasta el 7 de noviembre» for a multi-day all-day event.
     */
    public function shortDetail(): ?string
    {
        if (! $this->allDay) {
            return $this->start->format('H:i');
        }

        return $this->isMultiDay() ? 'Hasta el '.$this->dayWithoutWeekday($this->lastDay) : null;
    }

    /**
     * The whole date and time, for /eventos: «Jueves 5 de noviembre · 15:00
     * a 17:00», «Jueves 5 de noviembre · Todo el día», «Del jueves 5 de
     * noviembre al sábado 7 de noviembre».
     */
    public function when(): string
    {
        if ($this->allDay) {
            return $this->isMultiDay()
                ? 'Del '.$this->day($this->start).' al '.$this->day($this->lastDay)
                : Str::ucfirst($this->day($this->start)).' · Todo el día';
        }

        if ($this->end === null) {
            return Str::ucfirst($this->day($this->start)).' · '.$this->start->format('H:i');
        }

        if ($this->end->toDateString() === $this->start->toDateString()) {
            return Str::ucfirst($this->day($this->start)).' · '.$this->start->format('H:i').' a '.$this->end->format('H:i');
        }

        return 'Del '.$this->day($this->start).', '.$this->start->format('H:i').' al '.$this->day($this->end).', '.$this->end->format('H:i');
    }

    /**
     * «jueves 5 de noviembre, 15:00» (no time when it is all day).
     */
    public function dateAndTime(): string
    {
        return $this->longDate().($this->allDay ? '' : ', '.$this->start->format('H:i'));
    }

    /**
     * «Evento · jueves 5 de noviembre, 15:00», for the «Ctrl K» search.
     */
    public function searchDetail(): string
    {
        return 'Evento · '.$this->dateAndTime();
    }

    /** The event on /eventos. */
    public function href(): string
    {
        return route('events.index').'#e-'.$this->id;
    }

    /** «Agregar a mi calendario». */
    public function calendarHref(): string
    {
        return route('events.calendar', $this->id);
    }

    private function day(CarbonImmutable $date): string
    {
        return $this->spanish($date)->isoFormat('dddd D [de] MMMM').($date->year === $this->year ? '' : " de {$date->year}");
    }

    private function dayWithoutWeekday(CarbonImmutable $date): string
    {
        return $this->spanish($date)->isoFormat('D [de] MMMM').($date->year === $this->year ? '' : " de {$date->year}");
    }

    private function spanish(CarbonImmutable $date): CarbonImmutable
    {
        return $date->locale('es');
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
