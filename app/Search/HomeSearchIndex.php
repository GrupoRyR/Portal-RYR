<?php

namespace App\Search;

use App\Catalog\AppSearch;
use App\Models\Announcement;
use App\Models\DirectoryEntry;
use App\View\Home\AppCard;
use App\View\Home\EventDate;
use App\View\Home\HolidayDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the «Ctrl K» search of the home screen looks through, embedded in
 * the page as JSON (resources/views/components/home/search-dialog.blade.php),
 * so it also works in the saved copy of the page. Only what the viewer can
 * already see: their openable apps, the announcements addressed to their
 * company (App\Models\Announcement::scopeVisibleTo), upcoming Colombian
 * holidays and the upcoming company events they may see
 * (App\Models\Event::scopeVisibleTo), which link to /eventos#e-{id}, and
 * the people of the viewer's own company directory
 * (App\Models\DirectoryEntry::companyIdFor(), copied from Microsoft), which
 * link to /personas searching their name. Never the portal's accounts, and of a
 * person only the name, «Cargo · Área» and the search text (name, title,
 * area, office and email): no phone or dates.
 *
 * Every entry carries `search`, its text through AppSearch::normalize(), so
 * resources/js/home.js matches it exactly like the server search does.
 */
final readonly class HomeSearchIndex
{
    /** Most recent visible announcements in the index (pinned first). */
    public const ANNOUNCEMENT_LIMIT = 50;

    /** How far ahead the holidays and the events go. */
    public const HOLIDAY_MONTHS = 12;

    /** Soonest upcoming events in the index (all of them on the first /eventos page). */
    public const EVENT_LIMIT = 50;

    /** People in the index, by name (all of them on /personas). */
    public const PEOPLE_LIMIT = 1000;

    /** Characters of an announcement's text in its excerpt; the body never ships whole. */
    public const EXCERPT_LENGTH = 90;

    /**
     * @param  list<array{title: string, detail: string, search: string, href: string, newTab: bool}>  $apps
     * @param  list<array{title: string, detail: string, search: string, href: string}>  $announcements
     * @param  list<array{title: string, detail: string, search: string, date: string, end?: string, href?: string}>  $dates  Holidays and events by start; `date` is the last day, after which a saved copy hides it; an event's `end` (ISO 8601) is the moment it is over, when the saved copy hides it instead (like `data-event-end` on the home list).
     * @param  list<array{title: string, detail: string, search: string, href: string}>  $people  By name.
     */
    public function __construct(
        public array $apps,
        public array $announcements,
        public array $dates,
        public array $people = [],
    ) {}

    /**
     * @param  list<AppCard>  $cards  Every app on the page, whatever the search.
     * @param  iterable<Announcement>  $announcements  Visible to the viewer, in Announcement::scopeOrdered() order (the order of /anuncios).
     * @param  list<HolidayDay>  $holidays
     * @param  CarbonImmutable  $today  Midnight today in the calendar timezone.
     * @param  list<EventDate>  $events  Visible to the viewer, soonest first.
     * @param  iterable<DirectoryEntry>  $people  The viewer's company directory by name, with `display_name`, `job_title`, `department` and `search_text`.
     */
    public static function build(array $cards, iterable $announcements, array $holidays, CarbonImmutable $today, array $events = [], iterable $people = []): self
    {
        $apps = array_map(fn (AppCard $card) => [
            'title' => $card->name,
            'detail' => $card->categoryName,
            'search' => $card->searchText,
            'href' => $card->href,
            'newTab' => $card->opensInNewTab,
        ], $cards);

        $notices = [];
        foreach ($announcements as $announcement) {
            if (count($notices) >= self::ANNOUNCEMENT_LIMIT) {
                break;
            }

            $excerpt = Str::limit(Str::squish((string) $announcement->body), self::EXCERPT_LENGTH);
            $notices[] = [
                'title' => (string) $announcement->title,
                'detail' => $excerpt,
                'search' => AppSearch::normalize($announcement->title.' '.$excerpt),
                // Finds the page of /anuncios when followed, so a saved copy
                // of this page never points at a page that has moved on.
                'href' => route('announcements.show', $announcement->id),
            ];
        }

        $dates = [];
        foreach ($holidays as $day) {
            $date = $day->longDate().($day->date->year === $today->year ? '' : " de {$day->date->year}");

            $dates[] = [[$day->date->getTimestamp(), 0, 0], [
                'title' => $day->label(),
                'detail' => "{$day->kind()} · {$date}",
                'search' => AppSearch::normalize("{$day->label()} {$day->kind()} {$date}"),
                'date' => $day->isoDate(),
            ]];
        }

        foreach (array_slice($events, 0, self::EVENT_LIMIT) as $event) {
            $dates[] = [$event->sortKey(), [
                'title' => $event->title,
                'detail' => $event->searchDetail(),
                'search' => AppSearch::normalize("{$event->title} {$event->kind()} {$event->dateAndTime()}"),
                'date' => $event->lastIsoDate(),
                'end' => $event->overIso(),
                'href' => $event->href(),
            ]];
        }

        // By start, a holiday before the events of its day (HomePageBuilder::sortKey()).
        usort($dates, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $directory = [];
        foreach ($people as $person) {
            if (count($directory) >= self::PEOPLE_LIMIT) {
                break;
            }

            $directory[] = [
                'title' => (string) $person->display_name,
                'detail' => $person->roleLine(),
                'search' => (string) $person->search_text,
                // /personas finds the person by name, today's page and all.
                'href' => route('people.index', ['q' => (string) $person->display_name]),
            ];
        }

        return new self(array_values($apps), $notices, array_column($dates, 1), $directory);
    }

    /**
     * For the page's <script type="application/json">: `<`, `>`, `&` and
     * quotes are escaped, so no text can close the script element. A string
     * that is not valid UTF-8 gets U+FFFD instead of failing the home page.
     */
    public function toJson(): string
    {
        return json_encode([
            'apps' => $this->apps,
            'announcements' => $this->announcements,
            'dates' => $this->dates,
            'people' => $this->people,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
