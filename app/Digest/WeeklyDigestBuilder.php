<?php

namespace App\Digest;

use App\Announcements\AnnouncementListEmails;
use App\Calendar\ColombianHolidays;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\User;
use App\Models\WeeklyDigest;
use App\View\Home\Celebration;
use App\View\Home\EventDate;
use App\View\Home\HolidayDay;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The content of a company's Monday digest (odd/tasks/inicio-fase-2.md
 * WU3), with the same rules and helpers as the home screen, so the email
 * says what the portal shows. It goes to the company's all-staff list, so
 * it holds only what every person of the company sees: content addressed
 * to every company or to this one ({@see Announcement::scopeForCompanyId()});
 * audiences are whole companies, never groups or roles.
 *
 * - announcements published now that started since last week's digest
 *   ({@see announcementsSince()}), and the mandatory ones (`requires_read`)
 *   still in force, newest first: title, date and link, never the text;
 * - the dates from Monday to Sunday: holidays («Festivo», «Puente
 *   festivo»), the company's events (one-time, yearly and under way);
 * - the birthdays people chose to show (day and month) and the work
 *   anniversaries (day and years completed) of the company's directory.
 *
 * Reads the status of announcements and events at this moment (`now()`).
 * The week is Monday to Sunday in Bogotá; the stored dates are compared
 * in the app timezone (`config('app.timezone')`, which should stay
 * America/Bogota: the days and times printed are read in it).
 *
 * Not final: the tests put a double in front of build().
 */
class WeeklyDigestBuilder
{
    /**
     * Without last week's digest, the announcements start at last Monday's
     * first run (WeeklyDigests::FIRST_HOUR), in Bogotá.
     */
    public const FALLBACK_HOUR = WeeklyDigests::FIRST_HOUR;

    public function __construct(private readonly ColombianHolidays $holidays) {}

    /**
     * @param  CarbonImmutable  $weekStart  Monday, midnight in Bogotá.
     * @param  CarbonImmutable|null  $runAt  This run's moment, the one its row records as `attempted_at`; now when null.
     */
    public function build(Company $company, CarbonImmutable $weekStart, ?CarbonImmutable $runAt = null): WeeklyDigestContent
    {
        $runAt ??= CarbonImmutable::now();

        return new WeeklyDigestContent(
            weekStart: $weekStart,
            announcements: $this->announcements($company, $this->announcementsSince($company, $weekStart), $runAt),
            dates: $this->dates($company, $weekStart),
            celebrations: $this->celebrations($company, $weekStart),
        );
    }

    /**
     * Where this week's announcements start: the moment last week's digest
     * of the company was attempted (sent, empty, failed or interrupted),
     * so a late catch-up last week or this week neither repeats nor skips
     * one; without it, last Monday at FALLBACK_HOUR in Bogotá.
     */
    public function announcementsSince(Company $company, CarbonImmutable $weekStart): CarbonImmutable
    {
        $previous = WeeklyDigest::query()
            ->where('company_id', $company->id)
            ->where('week_start', $weekStart->subWeek()->toDateString())
            ->whereNotNull('attempted_at')
            ->first(['attempted_at']);

        return $previous?->attempted_at?->toImmutable() ?? $weekStart->subWeek()->setTime(self::FALLBACK_HOUR, 0);
    }

    /**
     * Published now, and either started after $since and by $runAt (the
     * previous digest listed up to its own run) or mandatory and in force.
     *
     * @return list<array{title: string, date: string, url: string, mandatory: bool}>
     */
    private function announcements(Company $company, CarbonImmutable $since, CarbonImmutable $runAt): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $timezone = config('app.timezone');
        [$since, $runAt] = [self::stored($since), self::stored($runAt)];

        return Announcement::query()
            ->select(['id', 'title', 'starts_at', 'is_pinned', 'requires_read'])
            ->inStatus(Announcement::STATUS_PUBLISHED)
            ->forCompanyId($company->id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('starts_at', '>', $since)->where('starts_at', '<=', $runAt))
                ->orWhere('requires_read', true))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'title' => AnnouncementListEmails::oneLine((string) $announcement->title),
                'date' => $announcement->starts_at->copy()->setTimezone($timezone)->locale('es')->isoFormat('dddd D [de] MMMM'),
                // The cron has no request: the link is built from APP_URL.
                'url' => $base.route('announcements.show', $announcement, false),
                'mandatory' => (bool) $announcement->requires_read,
            ])
            ->values()
            ->all();
    }

    /**
     * Holidays and events from Monday to Sunday, by day (a holiday before
     * the events of its day), as «Próximas fechas» orders them.
     *
     * @return list<array{when: string, kind: string, title: string}>
     */
    private function dates(Company $company, CarbonImmutable $weekStart): array
    {
        $nextMonday = $weekStart->addWeek();

        try {
            $holidays = HolidayDay::group($this->holidays->between($weekStart, $weekStart->addDays(6)), $weekStart);
        } catch (InvalidArgumentException) {
            // Past the calendar's supported range (ColombianHolidays).
            Log::warning('Weekly digest left out the holidays: the week is outside the holiday calendar.', [
                'company_id' => $company->id,
                'week_start' => $weekStart->toDateString(),
            ]);
            $holidays = [];
        }

        // Published and not over yet; a yearly one comes back on its day.
        $events = Event::query()
            ->select(['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly'])
            ->inStatus(Event::STATUS_PUBLISHED)
            ->forCompanyId($company->id)
            ->where(fn (Builder $query) => $query
                ->where('starts_at', '<', self::stored($nextMonday))
                ->orWhere(fn (Builder $query) => $query->yearly()))
            ->get();

        // Under way since last week, or starting before next Monday (an instant, whatever the timezone).
        $week = array_filter(
            EventDate::soonestFirst($events, $weekStart),
            fn (EventDate $event) => $event->isOngoing || $event->start < $nextMonday,
        );

        $dates = [...$holidays, ...$week];
        usort($dates, fn (HolidayDay|EventDate $a, HolidayDay|EventDate $b) => HomePageBuilder::sortKey($a) <=> HomePageBuilder::sortKey($b));

        return array_map(fn (HolidayDay|EventDate $date) => $date instanceof HolidayDay
            ? ['when' => Str::ucfirst($date->longDate()), 'kind' => $date->kind(), 'title' => $date->label()]
            : ['when' => $date->listedWhen(), 'kind' => $date->kind(), 'title' => AnnouncementListEmails::oneLine($date->title)], $dates);
    }

    /**
     * Birthdays (only those people chose to show, never the year) and work
     * anniversaries (the years completed, never the hire date) from Monday
     * to Sunday, from the company's directory, as «Mi día» reads them.
     *
     * @return list<array{when: string, name: string, label: string}>
     */
    private function celebrations(Company $company, CarbonImmutable $weekStart): array
    {
        $sunday = $weekStart->addDays(6);
        // Nobody's own row: nobody is viewing (no «Felicitar» is printed anyway).
        $rows = Celebration::within($this->people($company), new User, $weekStart, $company->short_name ?: Company::displayName($company));

        return array_values(array_map(
            fn (Celebration $row) => [
                'when' => Str::ucfirst($row->longDate()),
                'name' => AnnouncementListEmails::oneLine($row->name),
                'label' => AnnouncementListEmails::oneLine($row->label),
            ],
            array_filter($rows, fn (Celebration $row) => $row->date <= $sunday),
        ));
    }

    /**
     * The company's whole directory with the birthdays people chose to
     * show (DirectoryEntry::scopeCelebrationsOf(), the home screen's rule).
     *
     * @return Collection<int, DirectoryEntry>
     */
    private function people(Company $company): Collection
    {
        return DirectoryEntry::query()
            ->select([
                'directory_entries.id',
                'directory_entries.tenant_id',
                'directory_entries.azure_oid',
                'directory_entries.display_name',
                'directory_entries.email',
                'directory_entries.hire_date',
            ])
            ->celebrationsOf($company->id)
            ->orderBy('display_name')
            ->orderBy('directory_entries.id')
            ->get();
    }

    /**
     * The moment in the app timezone, as the database stores dates: a
     * bound Carbon is written in its own timezone.
     */
    private static function stored(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone(config('app.timezone'));
    }
}
