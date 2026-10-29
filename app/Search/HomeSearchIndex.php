<?php

namespace App\Search;

use App\Catalog\AppSearch;
use App\Models\Announcement;
use App\View\Home\AppCard;
use App\View\Home\HolidayDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the «Ctrl K» search of the home screen looks through, embedded in
 * the page as JSON (resources/views/components/home/search-dialog.blade.php),
 * so it also works in the saved copy of the page. Only what the viewer can
 * already see: their openable apps, the announcements addressed to their
 * company (App\Models\Announcement::scopeVisibleTo) and upcoming Colombian
 * holidays. No people and no other accounts.
 *
 * Every entry carries `search`, its text through AppSearch::normalize(), so
 * resources/js/home.js matches it exactly like the server search does.
 */
final readonly class HomeSearchIndex
{
    /** Most recent visible announcements in the index (pinned first). */
    public const ANNOUNCEMENT_LIMIT = 50;

    /** How far ahead the holidays go. */
    public const HOLIDAY_MONTHS = 12;

    /** Characters of an announcement's text in its excerpt; the body never ships whole. */
    public const EXCERPT_LENGTH = 90;

    /**
     * @param  list<array{title: string, detail: string, search: string, href: string, newTab: bool}>  $apps
     * @param  list<array{title: string, detail: string, search: string, href: string}>  $announcements
     * @param  list<array{title: string, detail: string, search: string, date: string}>  $dates
     */
    public function __construct(
        public array $apps,
        public array $announcements,
        public array $dates,
    ) {}

    /**
     * @param  list<AppCard>  $cards  Every app on the page, whatever the search.
     * @param  iterable<Announcement>  $announcements  Visible to the viewer, in Announcement::scopeOrdered() order (the order of /anuncios).
     * @param  list<HolidayDay>  $holidays
     * @param  CarbonImmutable  $today  Midnight today in the calendar timezone.
     */
    public static function build(array $cards, iterable $announcements, array $holidays, CarbonImmutable $today): self
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

        $dates = array_map(function (HolidayDay $day) use ($today) {
            $date = $day->longDate().($day->date->year === $today->year ? '' : " de {$day->date->year}");

            return [
                'title' => $day->label(),
                'detail' => "Festivo · {$date}",
                'search' => AppSearch::normalize("{$day->label()} festivo {$date}"),
                'date' => $day->isoDate(),
            ];
        }, $holidays);

        return new self(array_values($apps), $notices, array_values($dates));
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
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
