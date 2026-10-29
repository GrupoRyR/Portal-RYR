<?php

namespace App\View\Home;

use App\Calendar\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One day off in «Próximas fechas» and beside the date line of the home
 * screen. Two holidays can fall on the same day (Sagrado Corazón and San
 * Pedro y San Pablo on 30 June 2025): they share one entry with both names.
 */
final readonly class HolidayDay
{
    /**
     * @param  CarbonImmutable  $date  Midnight in the calendar timezone (App\Calendar\ColombianHolidays).
     * @param  non-empty-list<string>  $names  Sorted by name.
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $names,
        public bool $isToday,
    ) {}

    /**
     * Groups holidays sorted by date (ColombianHolidays::between()) by day.
     *
     * @param  list<Holiday>  $holidays
     * @param  CarbonImmutable  $today  Midnight today in the calendar timezone.
     * @return list<self>
     */
    public static function group(array $holidays, CarbonImmutable $today): array
    {
        $names = [];
        $dates = [];

        foreach ($holidays as $holiday) {
            $key = $holiday->date->toDateString();
            $dates[$key] ??= $holiday->date;
            $names[$key][] = $holiday->name;
        }

        $days = [];
        foreach ($dates as $key => $date) {
            $days[] = new self($date, $names[$key], $key === $today->toDateString());
        }

        return $days;
    }

    /**
     * «Sagrado Corazón; San Pedro y San Pablo»: a semicolon, since names hold «y».
     */
    public function label(): string
    {
        return implode('; ', $this->names);
    }

    /**
     * «lunes 2 de noviembre», for the middle of a sentence.
     */
    public function longDate(): string
    {
        return $this->spanish()->isoFormat('dddd D [de] MMMM');
    }

    public function dayNumber(): string
    {
        return (string) $this->date->day;
    }

    /**
     * «nov», without Carbon's trailing dot.
     */
    public function shortMonth(): string
    {
        return rtrim($this->spanish()->isoFormat('MMM'), '.');
    }

    /**
     * «Lunes», or «Hoy» when the holiday is today.
     */
    public function weekday(): string
    {
        return $this->isToday ? 'Hoy' : $this->dayName();
    }

    /**
     * «Lunes», even today: what the saved copy of the page shows once the day
     * is over (resources/js/home.js).
     */
    public function dayName(): string
    {
        return Str::ucfirst($this->spanish()->isoFormat('dddd'));
    }

    public function isoDate(): string
    {
        return $this->date->toDateString();
    }

    private function spanish(): CarbonImmutable
    {
        return $this->date->locale('es');
    }
}
