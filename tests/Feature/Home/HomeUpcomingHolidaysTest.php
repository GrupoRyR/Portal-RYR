<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\User;
use App\View\Home\HolidayDay;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
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
}
