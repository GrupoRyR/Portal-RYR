<?php

namespace App\View\Home;

use App\Calendar\ColombianHolidays;
use App\Catalog\AppCatalog;
use App\Catalog\AppSearch;
use App\Models\Announcement;
use App\Models\App;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\User;
use App\Search\HomeSearchIndex;
use App\Settings\PortalSettings;
use App\View\Components\AccountSwitcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds the home screen of one user from App\Catalog\AppCatalog:
 *
 * - favorites first (in the order they were added), taken out of their
 *   category so no app appears twice;
 * - categories in their order; categories without visible apps are left
 *   out (one emptied by favorites stays, empty, for the favorite toggle);
 * - up to five recent apps;
 * - Microsoft 365 quick links;
 * - up to three announcements for the user's company, with the user's
 *   read state of the mandatory ones (one query);
 * - the «Soporte TI» link when a support URL is configured;
 * - the person's other accounts for «Cambiar de empresa» (no query when
 *   the account is not linked);
 * - the next Colombian holidays for «Mi día» and the date line (computed,
 *   no query), from today in `config('app.timezone')`;
 * - the upcoming company events the user may see (one query), merged with
 *   the holidays in «Próximas fechas»;
 * - the index of the «Ctrl K» search (App\Search\HomeSearchIndex): every
 *   app on the page, the announcements of the same query, the holidays
 *   and events of the next twelve months, and the people of the user's
 *   company directory (one query, none on a server search);
 * - the «Personas» link (/personas) for users with a directory;
 * - «Cumpleaños y aniversarios» (App\View\Home\Celebration) from the same
 *   directory query: the birthdays people chose to show and the work
 *   anniversaries of today and the next seven days (none on a server search).
 *
 * A search (`?q=`) narrows favorites and categories and hides recents; that
 * page, the server search without JavaScript, carries no index.
 * Runs a fixed number of queries whatever the size of the catalog.
 */
final class HomePageBuilder
{
    public const RECENT_LIMIT = 5;

    public const ANNOUNCEMENT_LIMIT = 3;

    /** Days off listed in «Próximas fechas» (two holidays on one day count once). */
    public const UPCOMING_HOLIDAY_LIMIT = 5;

    /** How far ahead «Próximas fechas» looks; with nothing in it, the next holiday is shown alone. */
    public const UPCOMING_HOLIDAY_DAYS = 60;

    /** Events in «Próximas fechas» start within this many days (the holidays' horizon). */
    public const UPCOMING_EVENT_DAYS = self::UPCOMING_HOLIDAY_DAYS;

    /** Entries in «Próximas fechas», holidays and events together. */
    public const UPCOMING_DATE_LIMIT = self::UPCOMING_HOLIDAY_LIMIT;

    /** Roles offered the «Administración» link (each lands on its own section). */
    public const ADMIN_ROLES = ['super_admin', 'portal_admin', 'announcements_editor'];

    public function __construct(
        private readonly AppCatalog $catalog,
        private readonly PortalSettings $settings,
        private readonly ColombianHolidays $holidays,
    ) {}

    public function build(User $user, ?string $query = null, ?CarbonInterface $now = null): HomePage
    {
        $search = new AppSearch($query);
        $visible = $this->catalog->visibleApps($user);
        $favoriteIds = $this->favoriteIds($user, $visible);

        $cards = [];
        foreach ($visible->values() as $position => $app) {
            $cards[$app->id] = AppCard::from($app, $position, in_array($app->id, $favoriteIds, true));
        }

        $matches = fn (AppCard $card) => $search->matches($card->name, $card->description);

        $favorites = array_values(array_filter(
            array_map(fn (int $id) => $cards[$id], $favoriteIds),
            $matches,
        ));

        $categories = $this->categories($visible, $cards, $search);

        $recents = $search->isActive() ? [] : $this->catalog
            ->recentApps($user, self::RECENT_LIMIT, $visible)
            ->map(fn (App $app) => $cards[$app->id])
            ->all();

        $now ??= now();
        $greeting = Greeting::at($now);
        $company = $user->company;
        $today = CarbonImmutable::instance($now)->setTimezone(config('app.timezone'))->startOfDay();
        $upcoming = $this->upcomingHolidays($today);
        $visibleEvents = $this->events($user, $today);
        // The search and «Próximas fechas» look twelve months ahead; «Ver
        // eventos» shows for any visible upcoming event.
        $searchHorizon = $today->addMonths(HomeSearchIndex::HOLIDAY_MONTHS);
        $events = array_values(array_filter($visibleEvents, fn (EventDate $event) => $event->start < $searchHorizon));
        $upcomingDates = self::upcomingDates($upcoming, $events, $today);
        $directoryCompanyId = DirectoryEntry::companyIdFor($user);
        // A server search shows the cards only: no directory.
        $people = $search->isActive() ? new Collection : $this->people($directoryCompanyId);
        $celebrations = Celebration::within($people, $user, $today, $company?->short_name ?: Company::displayName($company));
        // A server search shows the cards only; the index wants more.
        $announcements = $this->announcements($user, $search->isActive() ? self::ANNOUNCEMENT_LIMIT : max(self::ANNOUNCEMENT_LIMIT, HomeSearchIndex::ANNOUNCEMENT_LIMIT));

        return new HomePage(
            salutation: $greeting->salutation,
            firstName: Greeting::firstName($user->name),
            dateLine: $greeting->dateLine,
            companyName: Company::displayName($company),
            userName: (string) $user->name,
            userInitials: self::initials((string) $user->name),
            userEmail: $user->email,
            canAdminister: $user->hasAnyRole(self::ADMIN_ROLES),
            appCount: count($cards),
            favorites: $favorites,
            categories: $categories,
            recents: $recents,
            quickLinks: QuickLinks::for($company),
            query: $search->isActive() ? $search->query() : '',
            resultCount: count($favorites) + array_sum(array_map(fn (CategoryGroup $group) => count($group->cards), $categories)),
            announcements: $announcements
                ->take(self::ANNOUNCEMENT_LIMIT)
                ->map(fn (Announcement $announcement) => AnnouncementCard::from($announcement, now: $now))
                ->values()
                ->all(),
            supportHref: $this->settings->supportUrl() === null ? null : route('support'),
            switcher: AccountSwitcher::for($user),
            accountsHref: $user->is_emergency ? null : route('account.show'),
            upcomingHolidays: $upcoming,
            nextHoliday: $upcoming[0] ?? null,
            searchIndex: $search->isActive() ? null : HomeSearchIndex::build(
                array_values($cards),
                $announcements,
                $this->holidaysAhead($today, $today->addMonths(HomeSearchIndex::HOLIDAY_MONTHS)->subDay()),
                $today,
                $events,
                $people,
            ),
            upcomingDates: $upcomingDates,
            hasEvents: $visibleEvents !== [],
            peopleHref: $directoryCompanyId === null ? null : route('people.index'),
            birthdayHref: $directoryCompanyId === null ? null : route('account.show').'#cumpleanos',
            celebrations: array_slice($celebrations, 0, Celebration::LIMIT),
            moreCelebrationsHref: count($celebrations) > Celebration::LIMIT ? route('people.index') : null,
        );
    }

    /**
     * Ids of the user's favorites that are still visible, oldest first.
     *
     * @param  Collection<int, App>  $visible
     * @return list<int>
     */
    private function favoriteIds(User $user, Collection $visible): array
    {
        if ($visible->isEmpty()) {
            return [];
        }

        return $user->favoriteApps()
            ->whereIn('apps.id', $visible->modelKeys())
            ->orderBy('user_favorites.created_at')
            ->orderBy('user_favorites.id')
            ->pluck('apps.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, App>  $visible
     * @param  array<int, AppCard>  $cards
     * @return list<CategoryGroup>
     */
    private function categories(Collection $visible, array $cards, AppSearch $search): array
    {
        $groups = $visible
            ->groupBy('app_category_id')
            ->sortBy([
                fn (Collection $a, Collection $b) => $a->first()->category->sort_order <=> $b->first()->category->sort_order,
                fn (Collection $a, Collection $b) => strcmp($a->first()->category->name, $b->first()->category->name),
            ]);

        $result = [];

        foreach ($groups as $apps) {
            $category = $apps->first()->category;
            $groupCards = $apps
                ->map(fn (App $app) => $cards[$app->id])
                ->reject(fn (AppCard $card) => $card->favorite)
                ->filter(fn (AppCard $card) => $search->matches($card->name, $card->description))
                ->values()
                ->all();

            // While searching, a category without matches is noise.
            if ($search->isActive() && $groupCards === []) {
                continue;
            }

            $result[] = new CategoryGroup($category->slug, $category->name, $groupCards);
        }

        return $result;
    }

    /**
     * The announcements of the cards and of the search index, in one query:
     * the first ANNOUNCEMENT_LIMIT make the cards.
     * The viewer's read state of mandatory ones comes in the same query.
     *
     * @return Collection<int, Announcement>
     */
    private function announcements(User $user, int $limit): Collection
    {
        if ($user->company_id === null) {
            return new Collection;
        }

        return Announcement::query()
            ->select(['id', 'title', 'body', 'image_path', 'starts_at', 'is_pinned', 'requires_read'])
            ->withReadAtFor($user)
            ->visibleTo($user)
            ->ordered()
            ->limit($limit)
            ->get();
    }

    /**
     * The events of «Próximas fechas», of the search index and of «Ver
     * eventos», in one query: visible (not over yet), soonest first, at most
     * HomeSearchIndex::EVENT_LIMIT. The caller keeps those that start within
     * HomeSearchIndex::HOLIDAY_MONTHS for the list and the search: being
     * soonest first, they are the same as filtering in the query. No query
     * for a user without a company, who sees none.
     *
     * @return list<EventDate>
     */
    private function events(User $user, CarbonImmutable $today): array
    {
        if ($user->company_id === null) {
            return [];
        }

        $events = Event::visibleSoonest($user, HomeSearchIndex::EVENT_LIMIT, ['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly']);

        return array_slice(EventDate::soonestFirst($events, $today), 0, HomeSearchIndex::EVENT_LIMIT);
    }

    /**
     * The company directory of the search index and of «Cumpleaños y
     * aniversarios», in one query: by name, at most
     * HomeSearchIndex::PEOPLE_LIMIT, only the columns those need. The
     * birthday (`shown_birthday_month`, `shown_birthday_day`) comes only
     * from the entry's own account, the one with its tenant, object ID and
     * company, and only when that account chose to show it; never through
     * `person_id`. No query without a directory (DirectoryEntry::companyIdFor()).
     * The query is DirectoryEntry::homeDirectory(), shared with the Claude
     * connector's `cumpleanos_y_aniversarios`.
     *
     * @return Collection<int, DirectoryEntry>
     */
    private function people(?int $companyId): Collection
    {
        if ($companyId === null) {
            return new Collection;
        }

        return DirectoryEntry::homeDirectory($companyId);
    }

    /**
     * Holidays and events that start within UPCOMING_EVENT_DAYS, by start
     * (a holiday before the events of its day), at most UPCOMING_DATE_LIMIT.
     *
     * @param  list<HolidayDay>  $holidays
     * @param  list<EventDate>  $events  Soonest first.
     * @return list<HolidayDay|EventDate>
     */
    private static function upcomingDates(array $holidays, array $events, CarbonImmutable $today): array
    {
        $horizon = $today->addDays(self::UPCOMING_EVENT_DAYS + 1);
        $dates = [
            ...$holidays,
            ...array_filter($events, fn (EventDate $event) => $event->start < $horizon),
        ];

        usort($dates, fn (HolidayDay|EventDate $a, HolidayDay|EventDate $b) => self::sortKey($a) <=> self::sortKey($b));

        return array_slice($dates, 0, self::UPCOMING_DATE_LIMIT);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function sortKey(HolidayDay|EventDate $date): array
    {
        return $date instanceof EventDate ? $date->sortKey() : [$date->date->getTimestamp(), 0, 0];
    }

    /**
     * Days off from today to $until, grouped by day; none past the
     * calendar's supported range (ColombianHolidays).
     *
     * @return list<HolidayDay>
     */
    private function holidaysAhead(CarbonImmutable $today, CarbonImmutable $until): array
    {
        try {
            return HolidayDay::group($this->holidays->between($today, $until), $today);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Days off from today on, up to UPCOMING_HOLIDAY_LIMIT within
     * UPCOMING_HOLIDAY_DAYS; with none in that window, just the next one.
     *
     * @return list<HolidayDay>
     */
    private function upcomingHolidays(CarbonImmutable $today): array
    {
        // Late in 9999 the horizon or the next holiday falls past the
        // calendar's supported range (ColombianHolidays), and both throw.
        try {
            $found = $this->holidays->between($today, $today->addDays(self::UPCOMING_HOLIDAY_DAYS));

            if ($found === []) {
                $next = $this->holidays->nextOnOrAfter($today)->date;
                $found = $this->holidays->between($next, $next);
            }
        } catch (InvalidArgumentException) {
            return [];
        }

        return array_slice(HolidayDay::group($found, $today), 0, self::UPCOMING_HOLIDAY_LIMIT);
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::upper(implode('', array_map(
            fn (string $word) => mb_substr($word, 0, 1),
            array_slice($words, 0, 2),
        )));
    }
}
