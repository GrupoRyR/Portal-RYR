<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\User;
use App\View\Home\HolidayDay;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HomePage::$upcomingHolidays and $nextHoliday: Colombian holidays from
 * today in the app timezone (App\Calendar\ColombianHolidays), no queries.
 */
class HomeUpcomingHolidaysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->microsoft(Company::factory()->create())->create();
    }

    public function test_upcoming_holidays_are_sorted_and_stop_at_the_horizon(): void
    {
        // Oct 29 + 60 days = Dec 28: Año Nuevo 2027 is out.
        $page = $this->build('2026-10-29 10:00');

        $this->assertSame(['2026-11-02', '2026-11-16', '2026-12-08', '2026-12-25'], $this->dates($page->upcomingHolidays));
        $this->assertSame(['Todos los Santos'], $page->upcomingHolidays[0]->names);
        $this->assertSame('2026-11-02', $page->nextHoliday?->isoDate());
    }

    public function test_upcoming_holidays_are_limited(): void
    {
        // Corpus Christi, today, and five more within 60 days: only five are shown.
        $page = $this->build('2026-06-08 12:00');

        $this->assertCount(HomePageBuilder::UPCOMING_HOLIDAY_LIMIT, $page->upcomingHolidays);
        $this->assertSame(['2026-06-08', '2026-06-15', '2026-06-29', '2026-07-13', '2026-07-20'], $this->dates($page->upcomingHolidays));
        $this->assertTrue($page->upcomingHolidays[0]->isToday);
    }

    public function test_the_next_holiday_is_shown_when_none_falls_within_the_horizon(): void
    {
        // Reyes Magos was on Monday 12 January; San José moves to Monday 23
        // March, 69 days later, past UPCOMING_HOLIDAY_DAYS.
        $page = $this->build('2026-01-13 10:00');

        $this->assertSame(['2026-03-23'], $this->dates($page->upcomingHolidays));
        $this->assertSame('2026-03-23', $page->nextHoliday?->isoDate());
    }

    public function test_two_holidays_on_the_same_day_share_one_entry(): void
    {
        $page = $this->build('2025-06-20 10:00');

        $this->assertSame(['2025-06-23', '2025-06-30', '2025-07-20', '2025-08-07', '2025-08-18'], $this->dates($page->upcomingHolidays));
        $this->assertSame(['Sagrado Corazón', 'San Pedro y San Pablo'], $page->upcomingHolidays[1]->names);
    }

    public function test_the_next_holiday_turns_into_today_at_midnight_in_bogota(): void
    {
        $eve = $this->build('2026-12-08 04:59', 'UTC'); // 23:59 on the 7th in Bogotá
        $this->assertSame('2026-12-08', $eve->nextHoliday?->isoDate());
        $this->assertFalse($eve->nextHoliday->isToday);
        $this->assertSame('Próximo festivo: martes 8 de diciembre, Inmaculada Concepción', $eve->holidayNotice());

        $day = $this->build('2026-12-08 05:00', 'UTC'); // midnight in Bogotá
        $this->assertTrue($day->nextHoliday?->isToday);
        $this->assertSame('Hoy es festivo: Inmaculada Concepción', $day->holidayNotice());
    }

    public function test_the_notice_names_both_holidays_of_one_day(): void
    {
        $this->assertSame(
            'Próximo festivo: lunes 30 de junio, Sagrado Corazón; San Pedro y San Pablo',
            $this->build('2025-06-24 10:00')->holidayNotice(),
        );
    }

    public function test_there_are_no_upcoming_holidays_past_the_supported_range(): void
    {
        // After Navidad 9999 the horizon (10000) is out of ColombianHolidays' range.
        $page = $this->build('9999-12-26 10:00');

        $this->assertSame([], $page->upcomingHolidays);
        $this->assertNull($page->nextHoliday);
        $this->assertNull($page->holidayNotice());
    }

    public function test_monday_and_friday_holidays_are_bridges(): void
    {
        // A holiday on Monday or Friday makes a three-day weekend: a «puente».
        $page = $this->build('2026-10-29 10:00');

        $this->assertSame([
            '2026-11-02' => 'Puente festivo', // Monday, moved by the Ley Emiliani
            '2026-11-16' => 'Puente festivo', // Monday
            '2026-12-08' => 'Festivo', // Tuesday
            '2026-12-25' => 'Puente festivo', // Friday
        ], $this->kinds($page->upcomingHolidays));
        $this->assertTrue($page->upcomingHolidays[3]->isBridge());
        $this->assertFalse($page->upcomingHolidays[2]->isBridge());
    }

    public function test_midweek_and_weekend_holidays_are_plain_holidays(): void
    {
        $page = $this->build('2027-11-16 10:00');

        $this->assertSame([
            '2027-12-08' => 'Festivo', // Wednesday
            '2027-12-25' => 'Festivo', // Saturday
            '2028-01-01' => 'Festivo', // Saturday
            '2028-01-10' => 'Puente festivo', // Monday
        ], $this->kinds($page->upcomingHolidays));
    }

    public function test_holiday_rows_of_the_home_screen_name_bridges(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'America/Bogota'));
        $rows = $this->holidayRows();
        $this->assertStringContainsString('Lunes · Puente festivo', $rows['2026-11-02']);
        $this->assertStringContainsString('Martes · Festivo', $rows['2026-12-08']);
        $this->assertStringNotContainsString('Puente', $rows['2026-12-08']);
        $this->assertStringContainsString('Viernes · Puente festivo', $rows['2026-12-25']);

        $this->travelTo(CarbonImmutable::parse('2027-11-16 10:00', 'America/Bogota'));
        $rows = $this->holidayRows();
        $this->assertStringContainsString('Miércoles · Festivo', $rows['2027-12-08']);
        $this->assertStringNotContainsString('Puente', $rows['2027-12-08']);
    }

    public function test_a_bridge_today_keeps_its_label(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'America/Bogota'));

        $this->assertStringContainsString('Hoy · Puente festivo', $this->holidayRows()['2026-11-02']);
    }

    private function build(string $time, string $timezone = 'America/Bogota'): HomePage
    {
        return app(HomePageBuilder::class)->build($this->user->fresh(), null, CarbonImmutable::parse($time, $timezone));
    }

    /**
     * @param  list<HolidayDay>  $days
     * @return list<string>
     */
    private function dates(array $days): array
    {
        return array_map(fn (HolidayDay $day) => $day->isoDate(), $days);
    }

    /**
     * @param  list<HolidayDay>  $days
     * @return array<string, string>
     */
    private function kinds(array $days): array
    {
        return array_combine($this->dates($days), array_map(fn (HolidayDay $day) => $day->kind(), $days));
    }

    /**
     * The text of each holiday row of «Próximas fechas», by date.
     *
     * @return array<string, string>
     */
    private function holidayRows(): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$this->actingAs($this->user->fresh())->get('/')->assertOk()->getContent());

        $rows = [];
        foreach ((new DOMXPath($document))->query('//section[@id="fechas"]//li[@data-holiday-date]') as $row) {
            $rows[$row->getAttribute('data-holiday-date')] = trim((string) preg_replace('/\s+/u', ' ', $row->textContent));
        }

        return $rows;
    }
}
