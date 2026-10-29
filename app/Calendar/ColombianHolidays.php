<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Colombian public holidays, computed from the law. Pure: no database, no network.
 *
 * Rules:
 * - Ley 51 de 1983, art. 1: six fixed dates (1 Jan, 1 May, 20 Jul, 7 Aug, 8 Dec,
 *   25 Dec) stay on their day, even on a Sunday. Seven dates (6 Jan, 19 Mar,
 *   29 Jun, 15 Aug, 12 Oct, 1 Nov, 11 Nov) move to the following Monday unless
 *   they already fall on a Monday.
 * - Easter (Gregorian, Meeus/Jones/Butcher): Jueves Santo (−3) and Viernes Santo
 *   (−2) stay; Ascensión (+39), Corpus Christi (+60) and Sagrado Corazón (+68)
 *   move to the following Monday, so they land on +43, +64 and +71.
 * - Ley 2347 de 2024 names 12 October «Día de la Diversidad Étnica y Cultural».
 * - Ley 2578 de 2026 adds 9 July (Nuestra Señora del Rosario de Chiquinquirá)
 *   from 2026 on, moved to Monday like the Ley 51 dates. A claim against it is
 *   pending at the Constitutional Court; if it falls, change FIRST_CHIQUINQUIRA_YEAR.
 *
 * That gives 18 holidays a year until 2025 and 19 from 2026. Years before 1984
 * get today's rules too: this is a calendar for the portal, not legal history.
 *
 * Two holidays on the same day are both kept, as two entries. It happens when
 * Easter falls between 19 and 25 April: Sagrado Corazón (+71) is then the same
 * Monday as San Pedro y San Pablo (30 June 2025). So forYear() always returns
 * every holiday, isHoliday() is true once, and entries on one day are sorted by
 * name, which makes nextOnOrAfter() deterministic.
 *
 * Days are read in the calendar timezone: pass config('app.timezone')
 * (America/Bogota). An instant is converted to that timezone before its day is
 * taken, so 1 January 03:00 UTC is still 31 December in Bogotá.
 */
final class ColombianHolidays
{
    public const FIRST_CHIQUINQUIRA_YEAR = 2026;

    private const FIXED = [
        [1, 1, 'Año Nuevo'],
        [5, 1, 'Día del Trabajo'],
        [7, 20, 'Día de la Independencia'],
        [8, 7, 'Batalla de Boyacá'],
        [12, 8, 'Inmaculada Concepción'],
        [12, 25, 'Navidad'],
    ];

    private const MOVED_TO_MONDAY = [
        [1, 6, 'Reyes Magos'],
        [3, 19, 'San José'],
        [6, 29, 'San Pedro y San Pablo'],
        [8, 15, 'Asunción de la Virgen'],
        [10, 12, 'Día de la Diversidad Étnica y Cultural'],
        [11, 1, 'Todos los Santos'],
        [11, 11, 'Independencia de Cartagena'],
    ];

    /** Days after Easter Sunday; the Monday ones are already moved. */
    private const EASTER_OFFSETS = [
        [-3, 'Jueves Santo'],
        [-2, 'Viernes Santo'],
        [43, 'Ascensión del Señor'],
        [64, 'Corpus Christi'],
        [71, 'Sagrado Corazón'],
    ];

    private const CHIQUINQUIRA = [7, 9, 'Nuestra Señora del Rosario de Chiquinquirá'];

    /** @var array<int, list<Holiday>> */
    private array $years = [];

    public function __construct(private readonly string $timezone = 'America/Bogota') {}

    /**
     * @return list<Holiday> sorted by date, then by name
     */
    public function forYear(int $year): array
    {
        return $this->years[$year] ??= $this->compute($year);
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        $day = $this->localDay($date);

        foreach ($this->forYear($day->year) as $holiday) {
            if ($holiday->date->equalTo($day)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first holiday on that day or later, crossing into the next year.
     */
    public function nextOnOrAfter(CarbonInterface $date): Holiday
    {
        $day = $this->localDay($date);

        foreach ([$day->year, $day->year + 1] as $year) {
            foreach ($this->forYear($year) as $holiday) {
                if ($holiday->date->greaterThanOrEqualTo($day)) {
                    return $holiday;
                }
            }
        }

        // Unreachable: 1 January of the next year is always a holiday.
        throw new InvalidArgumentException('No holiday found after '.$day->toDateString().'.');
    }

    /**
     * Holidays from one day to another, both included. Empty when $to is before $from.
     *
     * @return list<Holiday>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $this->localDay($from);
        $last = $this->localDay($to);
        $found = [];

        // Carbon's betweenIncluded() swaps reversed bounds; a reversed range is empty here.
        if ($last->lessThan($first)) {
            return [];
        }

        for ($year = $first->year; $year <= $last->year; $year++) {
            foreach ($this->forYear($year) as $holiday) {
                if ($holiday->date->betweenIncluded($first, $last)) {
                    $found[] = $holiday;
                }
            }
        }

        return $found;
    }

    /**
     * Gregorian Easter Sunday (anonymous algorithm, Meeus/Jones/Butcher), at
     * midnight in the given timezone.
     */
    public static function easterSunday(int $year, string $timezone = 'America/Bogota'): CarbonImmutable
    {
        self::assertGregorian($year);

        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);
    }

    /**
     * @return list<Holiday>
     */
    private function compute(int $year): array
    {
        self::assertGregorian($year);

        $holidays = [];

        foreach (self::FIXED as [$month, $day, $name]) {
            $holidays[] = new Holiday($this->day($year, $month, $day), $name);
        }

        $moved = self::MOVED_TO_MONDAY;
        if ($year >= self::FIRST_CHIQUINQUIRA_YEAR) {
            $moved[] = self::CHIQUINQUIRA;
        }

        foreach ($moved as [$month, $day, $name]) {
            $holidays[] = new Holiday($this->toMonday($this->day($year, $month, $day)), $name);
        }

        $easter = self::easterSunday($year, $this->timezone);
        foreach (self::EASTER_OFFSETS as [$offset, $name]) {
            $holidays[] = new Holiday($easter->addDays($offset), $name);
        }

        usort($holidays, fn (Holiday $a, Holiday $b): int => [$a->date->toDateString(), $a->name] <=> [$b->date->toDateString(), $b->name]);

        return $holidays;
    }

    private function day(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $this->timezone);
    }

    private function toMonday(CarbonImmutable $date): CarbonImmutable
    {
        return $date->isMonday() ? $date : $date->next(CarbonInterface::MONDAY);
    }

    private function localDay(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone($this->timezone)->startOfDay();
    }

    private static function assertGregorian(int $year): void
    {
        if ($year < 1583 || $year > 9999) {
            throw new InvalidArgumentException("Year {$year} is outside the Gregorian calendar range 1583–9999.");
        }
    }
}
