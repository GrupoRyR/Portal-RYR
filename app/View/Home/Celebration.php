<?php

namespace App\View\Home;

use App\Catalog\AppSearch;
use App\Models\DirectoryEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One row of «Cumpleaños y aniversarios» in «Mi día»: a birthday the person
 * chose to show (/cuenta) or a work anniversary (the hire date Microsoft
 * Entra holds, copied by App\Directory\DirectorySync), today or within the
 * next DAYS days in the calendar timezone (the Claude connector's
 * `cumpleanos_y_aniversarios` asks for up to 31). 29 February is
 * celebrated on 28 February in common years.
 */
final readonly class Celebration
{
    /** Days after today the block looks ahead (today and the next seven). */
    public const DAYS = 7;

    /** Rows in the block; with more, «Ver directorio». */
    public const LIMIT = 8;

    public const BIRTHDAY = 'birthday';

    public const ANNIVERSARY = 'anniversary';

    /**
     * @param  string  $kind  self::BIRTHDAY or self::ANNIVERSARY.
     * @param  string  $label  «Cumpleaños» or «5 años en Segurex».
     * @param  CarbonImmutable  $date  Midnight of the day, in the calendar timezone.
     * @param  string|null  $greetHref  «Felicitar» (a Teams chat with a greeting); null on the viewer's own row.
     * @param  int  $entryId  The directory entry it is about: two people with one name stay two rows.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $label,
        public CarbonImmutable $date,
        public bool $isToday,
        public bool $isTomorrow,
        public ?string $greetHref,
        public int $entryId,
    ) {}

    /**
     * The rows of the window, by date, then name (birthday before
     * anniversary), from the directory query of the home screen
     * (DirectoryEntry::homeDirectory()): `shown_birthday_month` and
     * `shown_birthday_day` hold the birthday of an account that chose to
     * show it, `hire_date` the hire date. The viewer's own row has no
     * «Felicitar».
     *
     * @param  iterable<DirectoryEntry>  $people  The viewer's company directory.
     * @param  CarbonImmutable  $today  Midnight today in the calendar timezone.
     * @param  string  $companyName  The company of the anniversaries: «5 años en Segurex».
     * @param  int  $days  Days after today the window reaches (DAYS on the home screen).
     * @return list<self>
     */
    public static function within(iterable $people, User $viewer, CarbonImmutable $today, string $companyName, int $days = self::DAYS): array
    {
        $rows = [];

        foreach ($people as $person) {
            $own = $viewer->tenant_id !== null
                && $person->tenant_id === $viewer->tenant_id
                && $person->azure_oid === $viewer->azure_oid;

            $month = $person->getAttribute('shown_birthday_month');
            $day = $person->getAttribute('shown_birthday_day');
            if ($month !== null && $day !== null && ($date = self::next((int) $month, (int) $day, $today, $days)) !== null) {
                $rows[] = self::make($person, self::BIRTHDAY, 'Cumpleaños', $date, $today, $own, '¡Feliz cumpleaños, '.self::firstName($person).'!');
            }

            $hired = $person->hire_date;
            if ($hired !== null && ($date = self::next($hired->month, $hired->day, $today, $days)) !== null) {
                $years = $date->year - $hired->year;
                if ($years >= 1) {
                    $span = $years === 1 ? '1 año' : "{$years} años";
                    $thanks = $years === 1 ? "este primer año en {$companyName}" : "estos {$years} años en {$companyName}";
                    $rows[] = self::make($person, self::ANNIVERSARY, "{$span} en {$companyName}", $date, $today, $own, '¡Feliz aniversario, '.self::firstName($person)."! Gracias por {$thanks}.");
                }
            }
        }

        usort($rows, fn (self $a, self $b) => [$a->date->getTimestamp(), AppSearch::normalize($a->name), $a->kind !== self::BIRTHDAY, $a->name]
            <=> [$b->date->getTimestamp(), AppSearch::normalize($b->name), $b->kind !== self::BIRTHDAY, $b->name]);

        return $rows;
    }

    /**
     * The next day/month on or after today, when it falls within $days days;
     * 29 February is 28 February in common years.
     */
    private static function next(int $month, int $day, CarbonImmutable $today, int $days): ?CarbonImmutable
    {
        if (! checkdate($month, $day, 2024)) {
            return null;
        }

        $last = $today->addDays($days);

        foreach ([$today->year, $today->year + 1] as $year) {
            $date = $month === 2 && $day === 29 && ! checkdate(2, 29, $year)
                ? $today->setDate($year, 2, 28)
                : $today->setDate($year, $month, $day);

            if ($date >= $today && $date <= $last) {
                return $date;
            }
        }

        return null;
    }

    private static function make(DirectoryEntry $person, string $kind, string $label, CarbonImmutable $date, CarbonImmutable $today, bool $own, string $greeting): self
    {
        return new self(
            name: (string) $person->display_name,
            kind: $kind,
            label: $label,
            date: $date,
            isToday: $date->isSameDay($today),
            isTomorrow: $date->isSameDay($today->addDay()),
            greetHref: $own ? null : $person->teamsChatUrl($greeting),
            entryId: (int) $person->id,
        );
    }

    private static function firstName(DirectoryEntry $person): string
    {
        return Greeting::firstName($person->display_name);
    }

    /**
     * «Hoy», «Mañana» or the weekday, «Lunes».
     */
    public function dayLabel(): string
    {
        return match (true) {
            $this->isToday => 'Hoy',
            $this->isTomorrow => 'Mañana',
            default => $this->dayName(),
        };
    }

    /**
     * «Lunes», even today: home.js relabels a saved copy replayed later.
     */
    public function dayName(): string
    {
        return Str::ucfirst($this->spanish()->isoFormat('dddd'));
    }

    /**
     * «lunes 2 de noviembre», for screen readers.
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

    public function isoDate(): string
    {
        return $this->date->toDateString();
    }

    private function spanish(): CarbonImmutable
    {
        return $this->date->locale('es');
    }
}
