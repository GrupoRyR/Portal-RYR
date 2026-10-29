<?php

namespace Tests\Unit\View;

use App\Calendar\Holiday;
use App\View\Home\HolidayDay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HolidayDayTest extends TestCase
{
    public function test_holidays_on_the_same_day_share_one_entry(): void
    {
        $today = CarbonImmutable::parse('2025-06-20', 'America/Bogota');

        $days = HolidayDay::group([
            $this->holiday('2025-06-23', 'Corpus Christi'),
            $this->holiday('2025-06-30', 'Sagrado Corazón'),
            $this->holiday('2025-06-30', 'San Pedro y San Pablo'),
            $this->holiday('2025-07-20', 'Día de la Independencia'),
        ], $today);

        $this->assertCount(3, $days);
        $this->assertSame(['Sagrado Corazón', 'San Pedro y San Pablo'], $days[1]->names);
        $this->assertSame('Sagrado Corazón; San Pedro y San Pablo', $days[1]->label());
    }

    public function test_the_dates_are_written_in_spanish(): void
    {
        $day = HolidayDay::group([$this->holiday('2026-11-02', 'Todos los Santos')], CarbonImmutable::parse('2026-10-29', 'America/Bogota'))[0];

        $this->assertSame('lunes 2 de noviembre', $day->longDate());
        $this->assertSame('2', $day->dayNumber());
        $this->assertSame('nov', $day->shortMonth());
        $this->assertSame('Lunes', $day->weekday());
        $this->assertSame('2026-11-02', $day->isoDate());
        $this->assertFalse($day->isToday);
    }

    public function test_today_is_flagged_and_named(): void
    {
        $day = HolidayDay::group([$this->holiday('2026-12-08', 'Inmaculada Concepción')], CarbonImmutable::parse('2026-12-08', 'America/Bogota'))[0];

        $this->assertTrue($day->isToday);
        $this->assertSame('Hoy', $day->weekday());
    }

    private function holiday(string $date, string $name): Holiday
    {
        return new Holiday(CarbonImmutable::parse($date, 'America/Bogota'), $name);
    }
}
