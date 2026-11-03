<?php

namespace App\Digest;

use App\Announcements\AnnouncementListEmails;
use App\Calendar\ColombianHolidays;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\User;
use App\View\Home\Celebration;
use App\View\Home\EventDate;
use App\View\Home\HolidayDay;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
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
 * - announcements published now that started in the last seven days, and
 *   the mandatory ones (`requires_read`) still in force, newest first:
 *   title, date and link, never the text;
 * - the dates from Monday to Sunday: holidays («Festivo», «Puente
 *   festivo»), the company's events (one-time, yearly and under way);
 * - the birthdays people chose to show (day and month) and the work
 *   anniversaries (day and years completed) of the company's directory.
 *
 * Reads the status of announcements and events at this moment (`now()`).
 */
final class WeeklyDigestBuilder
{
    /** Announcements published within this many days before the run. */
    public const ANNOUNCEMENT_DAYS = 7;

    public function __construct(private readonly ColombianHolidays $holidays) {}

    /**
     * @param  CarbonImmutable  $weekStart  Monday, midnight in Bogotá.
     */
    public function build(Company $company, CarbonImmutable $weekStart): WeeklyDigestContent
    {
        return new WeeklyDigestContent(
            weekStart: $weekStart,
            announcements: $this->announcements($company),
            dates: $this->dates($company, $weekStart),
            celebrations: $this->celebrations($company, $weekStart),
        );
    }

    /**
     * @return list<array{title: string, date: string, url: string, mandatory: bool}>
     */
    private function announcements(Company $company): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $timezone = config('app.timezone');

        return Announcement::query()
            ->select(['id', 'title', 'starts_at', 'is_pinned', 'requires_read'])
            ->inStatus(Announcement::STATUS_PUBLISHED)
            ->forCompanyId($company->id)
            ->where(fn (Builder $query) => $query
                ->where('starts_at', '>=', now()->subDays(self::ANNOUNCEMENT_DAYS))
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
            $holidays = [];
        }

        // Published and not over yet; a yearly one comes back on its day.
        $events = Event::query()
            ->select(['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly'])
            ->inStatus(Event::STATUS_PUBLISHED)
            ->forCompanyId($company->id)
            ->where(fn (Builder $query) => $query
                ->where('starts_at', '<', $nextMonday)
                ->orWhere(fn (Builder $query) => $query->yearly()))
            ->get();

        $week = array_filter(
            EventDate::soonestFirst($events, $weekStart),
            fn (EventDate $event) => $event->day < $nextMonday,
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
     * The company's directory with the birthday of each entry's own account
     * when it chose to show it: the same join as the home screen (tenant,
     * object ID and company of the entry).
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
                'users.birthday_month as shown_birthday_month',
                'users.birthday_day as shown_birthday_day',
            ])
            ->leftJoin('users', function (JoinClause $join) {
                $join->on('users.tenant_id', '=', 'directory_entries.tenant_id')
                    ->on('users.azure_oid', '=', 'directory_entries.azure_oid')
                    ->on('users.company_id', '=', 'directory_entries.company_id')
                    ->where('users.show_birthday', true);
            })
            ->where('directory_entries.company_id', $company->id)
            ->orderBy('display_name')
            ->orderBy('directory_entries.id')
            ->get();
    }
}
