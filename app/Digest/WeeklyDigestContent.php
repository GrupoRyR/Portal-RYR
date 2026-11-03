<?php

namespace App\Digest;

use Carbon\CarbonImmutable;

/**
 * What the Monday digest of one company tells for one week
 * (App\Digest\WeeklyDigestBuilder), as plain texts: the email view escapes
 * every one of them. Titles and names come on one line.
 */
final readonly class WeeklyDigestContent
{
    /**
     * @param  CarbonImmutable  $weekStart  Monday, midnight in Bogotá.
     * @param  list<array{title: string, date: string, url: string, mandatory: bool}>  $announcements
     * @param  list<array{when: string, kind: string, title: string}>  $dates  Holidays and events, by day.
     * @param  list<array{when: string, name: string, label: string}>  $celebrations  Birthdays and work anniversaries, by day.
     */
    public function __construct(
        public CarbonImmutable $weekStart,
        public array $announcements,
        public array $dates,
        public array $celebrations,
    ) {}

    /** Nothing to tell: the digest is not sent. */
    public function isEmpty(): bool
    {
        return $this->announcements === [] && $this->dates === [] && $this->celebrations === [];
    }

    /** Sunday, midnight in Bogotá. */
    public function weekEnd(): CarbonImmutable
    {
        return $this->weekStart->addDays(6);
    }

    /**
     * «Resumen de la semana del 9 al 15 de noviembre».
     */
    public function subject(): string
    {
        return 'Resumen de la semana '.$this->range();
    }

    /**
     * «del 9 al 15 de noviembre», «del 30 de noviembre al 6 de diciembre»,
     * «del 28 de diciembre de 2026 al 3 de enero de 2027».
     */
    public function range(): string
    {
        $start = $this->weekStart->locale('es');
        $end = $this->weekEnd()->locale('es');

        return match (true) {
            $start->year !== $end->year => 'del '.$start->isoFormat('D [de] MMMM [de] YYYY').' al '.$end->isoFormat('D [de] MMMM [de] YYYY'),
            $start->month !== $end->month => 'del '.$start->isoFormat('D [de] MMMM').' al '.$end->isoFormat('D [de] MMMM'),
            default => 'del '.$start->day.' al '.$end->isoFormat('D [de] MMMM'),
        };
    }
}
