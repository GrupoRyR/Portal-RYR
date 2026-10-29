<?php

namespace App\View\Home;

use App\Calendar\ColombianHolidays;
use App\Catalog\AppCatalog;
use App\Catalog\AppSearch;
use App\Models\Announcement;
use App\Models\App;
use App\Models\Company;
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
 * - up to three announcements for the user's company (one query);
 * - the «Soporte TI» link when a support URL is configured;
 * - the person's other accounts for «Cambiar de empresa» (no query when
 *   the account is not linked);
 * - the next Colombian holidays for «Mi día» and the date line (computed,
 *   no query), from today in `config('app.timezone')`;
 * - the index of the «Ctrl K» search (App\Search\HomeSearchIndex): every
 *   app on the page, the announcements of the same query and the holidays
 *   of the next twelve months.
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
            ),
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
     *
     * @return Collection<int, Announcement>
     */
    private function announcements(User $user, int $limit): Collection
    {
        if ($user->company_id === null) {
            return new Collection;
        }

        return Announcement::query()
            ->visibleTo($user)
            ->ordered()
            ->limit($limit)
            ->get(['id', 'title', 'body', 'image_path', 'starts_at', 'is_pinned']);
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
