<?php

namespace Tests\Unit\Calendar;

use App\Calendar\ColombianHolidays;
use App\Calendar\Holiday;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected lists, checked on 2026-10-29 against:
 * - Ley 51 de 1983, art. 1 (fixed dates and the Monday rule):
 *   https://www.alcaldiabogota.gov.co/sisjur/normas/Norma1.jsp?i=4954
 * - Ley 2347 de 2024, Diario Oficial 52.663 (12 October is the «Día de la
 *   Diversidad Étnica y Cultural de la nación colombiana»):
 *   https://sidn.ramajudicial.gov.co/SIDN//NORMATIVA/TEXTOS_COMPLETOS/7_LEYES/LEYES%202024/Ley%202347%20de%202024.pdf
 * - Ley 2578 de 2026 (9 July, Nuestra Señora del Rosario de Chiquinquirá, a
 *   national holiday «conforme a la legislación vigente sobre días festivos»):
 *   https://urosario.edu.co/sites/default/files/2026-06/resumen-de-la-ley-2578-de-2026.pdf
 * - Published calendars for 2025 (both Sagrado Corazón and San Pedro on 30 June),
 *   2026 and 2027 (Chiquinquirá on 12 July 2027):
 *   https://www.noticiascaracol.com/colombia/junio-de-2025-tendra-tres-puentes-festivos-segun-el-calendario-cuando-caen-so35
 *   https://www.noticiascaracol.com/colombia/calendario-de-dias-festivos-en-colombia-2026-fechas-puentes-y-descansos-so35
 *   https://www.colombia.com/actualidad/nacionales/que-dia-cae-el-nuevo-festivo-de-julio-de-2026-en-colombia-y-por-que-cambio-586725
 *   https://www.publimetro.co/noticias/2026/10/02/calendario-2027-es-hora-de-programarse-con-los-festivos-en-colombia-y-las-vacaciones/
 */
class ColombianHolidaysTest extends TestCase
{
    /**
     * @return array<string, array{int, list<array{string, string}>}>
     */
    public static function fullYears(): array
    {
        return [
            '2025 (Easter 20 April)' => [2025, [
                ['2025-01-01', 'Año Nuevo'],
                ['2025-01-06', 'Reyes Magos'],
                ['2025-03-24', 'San José'],
                ['2025-04-17', 'Jueves Santo'],
                ['2025-04-18', 'Viernes Santo'],
                ['2025-05-01', 'Día del Trabajo'],
                ['2025-06-02', 'Ascensión del Señor'],
                ['2025-06-23', 'Corpus Christi'],
                ['2025-06-30', 'Sagrado Corazón'],
                ['2025-06-30', 'San Pedro y San Pablo'],
                ['2025-07-20', 'Día de la Independencia'],
                ['2025-08-07', 'Batalla de Boyacá'],
                ['2025-08-18', 'Asunción de la Virgen'],
                ['2025-10-13', 'Día de la Diversidad Étnica y Cultural'],
                ['2025-11-03', 'Todos los Santos'],
                ['2025-11-17', 'Independencia de Cartagena'],
                ['2025-12-08', 'Inmaculada Concepción'],
                ['2025-12-25', 'Navidad'],
            ]],
            '2026 (Easter 5 April)' => [2026, [
                ['2026-01-01', 'Año Nuevo'],
                ['2026-01-12', 'Reyes Magos'],
                ['2026-03-23', 'San José'],
                ['2026-04-02', 'Jueves Santo'],
                ['2026-04-03', 'Viernes Santo'],
                ['2026-05-01', 'Día del Trabajo'],
                ['2026-05-18', 'Ascensión del Señor'],
                ['2026-06-08', 'Corpus Christi'],
                ['2026-06-15', 'Sagrado Corazón'],
                ['2026-06-29', 'San Pedro y San Pablo'],
                ['2026-07-13', 'Nuestra Señora del Rosario de Chiquinquirá'],
                ['2026-07-20', 'Día de la Independencia'],
                ['2026-08-07', 'Batalla de Boyacá'],
                ['2026-08-17', 'Asunción de la Virgen'],
                ['2026-10-12', 'Día de la Diversidad Étnica y Cultural'],
                ['2026-11-02', 'Todos los Santos'],
                ['2026-11-16', 'Independencia de Cartagena'],
                ['2026-12-08', 'Inmaculada Concepción'],
                ['2026-12-25', 'Navidad'],
            ]],
            '2027 (Easter 28 March)' => [2027, [
                ['2027-01-01', 'Año Nuevo'],
                ['2027-01-11', 'Reyes Magos'],
                ['2027-03-22', 'San José'],
                ['2027-03-25', 'Jueves Santo'],
                ['2027-03-26', 'Viernes Santo'],
                ['2027-05-01', 'Día del Trabajo'],
                ['2027-05-10', 'Ascensión del Señor'],
                ['2027-05-31', 'Corpus Christi'],
                ['2027-06-07', 'Sagrado Corazón'],
                ['2027-07-05', 'San Pedro y San Pablo'],
                ['2027-07-12', 'Nuestra Señora del Rosario de Chiquinquirá'],
                ['2027-07-20', 'Día de la Independencia'],
                ['2027-08-07', 'Batalla de Boyacá'],
                ['2027-08-16', 'Asunción de la Virgen'],
                ['2027-10-18', 'Día de la Diversidad Étnica y Cultural'],
                ['2027-11-01', 'Todos los Santos'],
                ['2027-11-15', 'Independencia de Cartagena'],
                ['2027-12-08', 'Inmaculada Concepción'],
                ['2027-12-25', 'Navidad'],
            ]],
        ];
    }

    /**
     * @param  list<array{string, string}>  $expected
     */
    #[DataProvider('fullYears')]
    public function test_a_full_year_matches_the_published_calendar(int $year, array $expected): void
    {
        $this->assertSame($expected, $this->pairs((new ColombianHolidays)->forYear($year)));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function easterSundays(): array
    {
        return [
            '2000' => [2000, '2000-04-23'],
            '2008, early March' => [2008, '2008-03-23'],
            '2011, late April' => [2011, '2011-04-24'],
            '2024, March' => [2024, '2024-03-31'],
            '2025' => [2025, '2025-04-20'],
            '2026' => [2026, '2026-04-05'],
            '2027, March' => [2027, '2027-03-28'],
            '2038, latest possible' => [2038, '2038-04-25'],
        ];
    }

    #[DataProvider('easterSundays')]
    public function test_easter_sunday_follows_the_gregorian_computus(int $year, string $expected): void
    {
        $easter = ColombianHolidays::easterSunday($year);

        $this->assertSame($expected, $easter->toDateString());
        $this->assertTrue($easter->isSunday());
    }

    public function test_the_easter_holidays_keep_their_weekdays_in_march_and_in_april(): void
    {
        $holidays = new ColombianHolidays;

        foreach ([2024, 2027, 2038] as $year) {
            $byName = [];
            foreach ($holidays->forYear($year) as $holiday) {
                $byName[$holiday->name] = $holiday->date;
            }

            $this->assertTrue($byName['Jueves Santo']->isThursday(), "{$year} Jueves Santo");
            $this->assertTrue($byName['Viernes Santo']->isFriday(), "{$year} Viernes Santo");
            $this->assertTrue($byName['Ascensión del Señor']->isMonday(), "{$year} Ascensión");
            $this->assertTrue($byName['Corpus Christi']->isMonday(), "{$year} Corpus Christi");
            $this->assertTrue($byName['Sagrado Corazón']->isMonday(), "{$year} Sagrado Corazón");
        }

        $this->assertSame('2024-03-28', $this->dateOf($holidays->forYear(2024), 'Jueves Santo'));
        $this->assertSame('2038-04-22', $this->dateOf($holidays->forYear(2038), 'Jueves Santo'));
    }

    public function test_a_moved_holiday_that_already_falls_on_a_monday_stays(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertSame('2025-01-06', $this->dateOf($holidays->forYear(2025), 'Reyes Magos'));
        $this->assertSame('2027-11-01', $this->dateOf($holidays->forYear(2027), 'Todos los Santos'));
    }

    public function test_a_fixed_holiday_on_a_sunday_is_not_moved(): void
    {
        $this->assertSame('2025-07-20', $this->dateOf((new ColombianHolidays)->forYear(2025), 'Día de la Independencia'));
    }

    public function test_two_holidays_on_the_same_day_are_both_kept(): void
    {
        $holidays = new ColombianHolidays;
        $day = CarbonImmutable::create(2025, 6, 30, 0, 0, 0, 'America/Bogota');

        $this->assertCount(18, $holidays->forYear(2025));
        $this->assertTrue($holidays->isHoliday($day));
        $this->assertSame(
            [['2025-06-30', 'Sagrado Corazón'], ['2025-06-30', 'San Pedro y San Pablo']],
            $this->pairs($holidays->between($day, $day)),
        );
        $this->assertSame('Sagrado Corazón', $holidays->nextOnOrAfter($day->subDays(3))->name);
    }

    public function test_chiquinquira_is_a_holiday_from_2026_on(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertNull($this->dateOf($holidays->forYear(2025), 'Nuestra Señora del Rosario de Chiquinquirá'));
        $this->assertSame('2028-07-10', $this->dateOf($holidays->forYear(2028), 'Nuestra Señora del Rosario de Chiquinquirá'));
    }

    public function test_chiquinquira_has_no_last_year_while_the_law_is_in_force(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertNull(ColombianHolidays::LAST_CHIQUINQUIRA_YEAR);
        $this->assertSame('2026-07-13', $this->dateOf($holidays->forYear(2026), 'Nuestra Señora del Rosario de Chiquinquirá'));
        $this->assertSame('2027-07-12', $this->dateOf($holidays->forYear(2027), 'Nuestra Señora del Rosario de Chiquinquirá'));
        $this->assertSame('2028-07-10', $this->dateOf($holidays->forYear(2028), 'Nuestra Señora del Rosario de Chiquinquirá'));
    }

    public function test_a_last_chiquinquira_year_keeps_the_years_already_observed(): void
    {
        // A ruling is not retroactive: 2026 stays a holiday, the years after it do not.
        $holidays = new ColombianHolidays('America/Bogota', lastChiquinquiraYear: 2026);

        $this->assertSame('2026-07-13', $this->dateOf($holidays->forYear(2026), 'Nuestra Señora del Rosario de Chiquinquirá'));
        $this->assertCount(19, $holidays->forYear(2026));
        $this->assertNull($this->dateOf($holidays->forYear(2027), 'Nuestra Señora del Rosario de Chiquinquirá'));
        $this->assertCount(18, $holidays->forYear(2027));
    }

    public function test_dates_are_midnight_in_the_given_timezone(): void
    {
        foreach ((new ColombianHolidays('America/Bogota'))->forYear(2026) as $holiday) {
            $this->assertInstanceOf(Holiday::class, $holiday);
            $this->assertSame('America/Bogota', $holiday->date->getTimezone()->getName());
            $this->assertSame('00:00:00', $holiday->date->format('H:i:s'));
        }
    }

    public function test_is_holiday_reads_the_day_in_the_calendar_timezone(): void
    {
        $holidays = new ColombianHolidays('America/Bogota');

        $this->assertTrue($holidays->isHoliday(CarbonImmutable::parse('2026-07-20 23:59', 'America/Bogota')));
        $this->assertFalse($holidays->isHoliday(CarbonImmutable::parse('2026-07-21 00:00', 'America/Bogota')));

        // 03:00 UTC on 1 January is still 31 December in Bogotá (UTC−5).
        $this->assertFalse($holidays->isHoliday(CarbonImmutable::parse('2026-01-01 03:00', 'UTC')));
        $this->assertTrue($holidays->isHoliday(CarbonImmutable::parse('2026-01-01 12:00', 'UTC')));
    }

    public function test_next_on_or_after_a_holiday_returns_that_holiday(): void
    {
        $next = (new ColombianHolidays)->nextOnOrAfter(CarbonImmutable::parse('2026-07-20 18:30', 'America/Bogota'));

        $this->assertSame(['2026-07-20', 'Día de la Independencia'], [$next->date->toDateString(), $next->name]);
    }

    public function test_next_on_or_after_a_working_day_returns_the_following_holiday(): void
    {
        $next = (new ColombianHolidays)->nextOnOrAfter(CarbonImmutable::parse('2026-10-29', 'America/Bogota'));

        $this->assertSame(['2026-11-02', 'Todos los Santos'], [$next->date->toDateString(), $next->name]);
    }

    public function test_next_on_or_after_crosses_the_end_of_the_year(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertSame('2027-01-01', $holidays->nextOnOrAfter(CarbonImmutable::parse('2026-12-26', 'America/Bogota'))->date->toDateString());
        $this->assertSame('2027-01-01', $holidays->nextOnOrAfter(CarbonImmutable::parse('2026-12-31 23:59', 'America/Bogota'))->date->toDateString());
    }

    public function test_between_is_inclusive_and_crosses_years(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertSame(
            [['2026-06-08', 'Corpus Christi'], ['2026-06-15', 'Sagrado Corazón'], ['2026-06-29', 'San Pedro y San Pablo']],
            $this->pairs($holidays->between(CarbonImmutable::parse('2026-06-08', 'America/Bogota'), CarbonImmutable::parse('2026-06-29', 'America/Bogota'))),
        );
        $this->assertSame(
            [['2026-12-25', 'Navidad'], ['2027-01-01', 'Año Nuevo'], ['2027-01-11', 'Reyes Magos']],
            $this->pairs($holidays->between(CarbonImmutable::parse('2026-12-20', 'America/Bogota'), CarbonImmutable::parse('2027-01-15', 'America/Bogota'))),
        );
        $this->assertSame([], $holidays->between(CarbonImmutable::parse('2026-02-01', 'America/Bogota'), CarbonImmutable::parse('2026-02-28', 'America/Bogota')));
        $this->assertSame([], $holidays->between(CarbonImmutable::parse('2026-12-31', 'America/Bogota'), CarbonImmutable::parse('2026-01-01', 'America/Bogota')));
    }

    public function test_years_far_apart_still_compute(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertCount(18, $holidays->forYear(2000));
        $this->assertCount(19, $holidays->forYear(2100));
        $this->assertSame('2000-04-20', $this->dateOf($holidays->forYear(2000), 'Jueves Santo'));
        $this->assertSame('2101-01-01', $holidays->nextOnOrAfter(CarbonImmutable::parse('2100-12-26', 'America/Bogota'))->date->toDateString());

        $sorted = array_map(fn (Holiday $holiday): string => $holiday->date->toDateString(), $holidays->forYear(2100));
        $copy = $sorted;
        sort($copy);
        $this->assertSame($copy, $sorted);
    }

    public function test_next_on_or_after_late_in_the_last_year_explains_the_range(): void
    {
        $holidays = new ColombianHolidays;

        $this->assertSame('9999-12-25', $holidays->nextOnOrAfter(CarbonImmutable::parse('9999-12-25', 'America/Bogota'))->date->toDateString());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No holiday on or after 9999-12-26 within the supported range 1583–9999.');

        $holidays->nextOnOrAfter(CarbonImmutable::parse('9999-12-26', 'America/Bogota'));
    }

    public function test_years_outside_the_gregorian_calendar_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ColombianHolidays)->forYear(1582);
    }

    /**
     * @param  list<Holiday>  $holidays
     * @return list<array{string, string}>
     */
    private function pairs(array $holidays): array
    {
        return array_map(fn (Holiday $holiday): array => [$holiday->date->toDateString(), $holiday->name], $holidays);
    }

    /**
     * @param  list<Holiday>  $holidays
     */
    private function dateOf(array $holidays, string $name): ?string
    {
        foreach ($holidays as $holiday) {
            if ($holiday->name === $name) {
                return $holiday->date->toDateString();
            }
        }

        return null;
    }
}
