<?php

namespace App\View\Home;

use App\ItStatus\ItStatus;
use App\Search\HomeSearchIndex;
use App\View\Notifications\Notification;
use App\View\Notifications\NotificationList;

/**
 * The view model of the home screen (resources/views/home.blade.php). The
 * view only prints it: every query and decision happens in
 * HomePageBuilder.
 */
final readonly class HomePage
{
    /**
     * @param  list<AppCard>  $favorites
     * @param  list<CategoryGroup>  $categories
     * @param  list<AppCard>  $recents
     * @param  list<QuickLink>  $quickLinks
     * @param  list<AnnouncementCard>  $announcements  Newest pinned first; at most HomePageBuilder::ANNOUNCEMENT_LIMIT.
     * @param  string|null  $supportHref  «Soporte TI» link (`/soporte`); null hides the button.
     * @param  array{current: string, targets: list<array{id: int, label: string}>, menu: bool}|null  $switcher  «Cambiar de empresa» (App\View\Components\AccountSwitcher::for()); null hides it.
     * @param  string|null  $accountsHref  «Cuentas vinculadas» (`/cuenta`) in the account menu; null hides it (emergency accounts).
     * @param  list<HolidayDay>  $upcomingHolidays  The holidays of «Próximas fechas», from today: see HomePageBuilder::UPCOMING_HOLIDAY_LIMIT and UPCOMING_HOLIDAY_DAYS.
     * @param  HolidayDay|null  $nextHoliday  Today's holiday or the next one, beside the date line.
     * @param  HomeSearchIndex|null  $searchIndex  The «Ctrl K» search; null (a server search, `?q=`) leaves the dialog out.
     * @param  list<HolidayDay|EventDate>  $upcomingDates  «Próximas fechas»: holidays and company events by start, at most HomePageBuilder::UPCOMING_DATE_LIMIT.
     * @param  bool  $hasEvents  Some of them are events: «Ver eventos» links to /eventos.
     * @param  string|null  $peopleHref  «Personas» (/personas, the company directory); null hides it (no directory for this account).
     * @param  string|null  $birthdayHref  «Mi cumpleaños» (`/cuenta#cumpleanos`) in the account menu; null hides it (no directory for this account).
     * @param  list<Celebration>  $celebrations  «Cumpleaños y aniversarios»: today and the next Celebration::DAYS days, at most Celebration::LIMIT; empty hides the block.
     * @param  string|null  $moreCelebrationsHref  «Ver directorio» when more fall in those days than the block shows.
     * @param  list<MarketFigure>  $markets  The «Mercados» band (TRM, euro); empty hides it (before the first sync).
     * @param  list<NewsTab>  $news  «Noticias»: the tabs with headlines of the user's company; empty hides the block.
     * @param  ProductShelf|null  $catalog  «Catálogos y categorías»; null hides it (nothing published, or a server search).
     * @param  ManagementCard|null  $management  «Panel gerencial»; null hides it (not «Gerencia» of this company, or no figures yet).
     * @param  WorkList|null  $work  «Tareas pendientes» of «Mi día»; null when nothing is pending (the block still shows a «Completar» message).
     * @param  ItStatus|null  $itStatus  «Estado de TI» of the active company; null hides it (no active company, or the emergency account).
     * @param  NotificationList|null  $notifications  The bell of the header (HomePageBuilder always sets it); null hides it.
     */
    public function __construct(
        public string $salutation,
        public string $firstName,
        public string $dateLine,
        public string $companyName,
        public string $userName,
        public string $userInitials,
        public ?string $userEmail,
        public bool $canAdminister,
        public int $appCount,
        public array $favorites,
        public array $categories,
        public array $recents,
        public array $quickLinks,
        public string $query,
        public int $resultCount,
        public array $announcements = [],
        public ?string $supportHref = null,
        public ?array $switcher = null,
        public ?string $accountsHref = null,
        public array $upcomingHolidays = [],
        public ?HolidayDay $nextHoliday = null,
        public ?HomeSearchIndex $searchIndex = null,
        public array $upcomingDates = [],
        public bool $hasEvents = false,
        public ?string $peopleHref = null,
        public ?string $birthdayHref = null,
        public array $celebrations = [],
        public ?string $moreCelebrationsHref = null,
        public array $markets = [],
        public array $news = [],
        public ?ProductShelf $catalog = null,
        public ?ManagementCard $management = null,
        public ?WorkList $work = null,
        public ?ItStatus $itStatus = null,
        public ?NotificationList $notifications = null,
    ) {}

    /**
     * «Hoy es festivo: …» or «Próximo festivo: martes 8 de diciembre, Inmaculada Concepción».
     */
    public function holidayNotice(): ?string
    {
        if ($this->nextHoliday === null) {
            return null;
        }

        return $this->nextHoliday->isToday
            ? "Hoy es festivo: {$this->nextHoliday->label()}"
            : "Próximo festivo: {$this->nextHoliday->longDate()}, {$this->nextHoliday->label()}";
    }

    /**
     * The «Secciones» menu of phones: only sections the view renders and
     * shows, as anchors, in page order (home.blade.php keeps the same
     * conditions): the main column, then «Mi día». Empty, so no menu,
     * without apps: that page is short, and the menu would crowd the
     * company name off the logo row.
     *
     * @return list<array{href: string, label: string, offline?: false}>
     */
    public function sections(): array
    {
        if (! $this->hasApps()) {
            return [];
        }

        $sections = [['href' => '#contenido', 'label' => 'Inicio']];

        if ($this->management !== null) {
            // Left out of the saved offline copy, like the panel (resources/js/sw.js).
            $sections[] = ['href' => '#panel-gerencial', 'label' => 'Panel gerencial', 'offline' => false];
        }

        // A search hides Favoritos without matches (home.blade.php).
        if (! $this->isSearching() || $this->favorites !== []) {
            $sections[] = ['href' => '#favoritos', 'label' => 'Favoritos'];
        }

        if ($this->recents !== []) {
            $sections[] = ['href' => '#recientes', 'label' => 'Recientes'];
        }

        // Empty categories are hidden; a search drops those without matches.
        if (array_filter($this->categories, fn (CategoryGroup $group) => ! $group->isEmpty()) !== []) {
            $sections[] = ['href' => '#aplicaciones', 'label' => 'Aplicaciones'];
        }

        if ($this->quickLinks !== []) {
            $sections[] = ['href' => '#accesos', 'label' => 'Accesos rápidos'];
        }

        if ($this->announcements !== []) {
            $sections[] = ['href' => '#anuncios', 'label' => 'Anuncios'];
        }

        if ($this->news !== []) {
            $sections[] = ['href' => '#noticias', 'label' => 'Noticias'];
        }

        if ($this->catalog !== null) {
            $sections[] = ['href' => '#catalogos', 'label' => 'Catálogos'];
        }

        if ($this->work !== null) {
            $sections[] = ['href' => '#tareas', 'label' => 'Tareas'];
        }

        if ($this->celebrations !== []) {
            $sections[] = ['href' => '#celebraciones', 'label' => 'Cumpleaños'];
        }

        if ($this->upcomingDates !== []) {
            $sections[] = ['href' => '#fechas', 'label' => 'Próximas fechas'];
        }

        if ($this->itStatus !== null) {
            $sections[] = ['href' => '#estado-ti', 'label' => 'Estado de TI'];
        }

        return $sections;
    }

    /**
     * «Por confirmar» of «Mi día»: the mandatory announcements the person
     * has not confirmed, newest first, as the bell of the header lists
     * them (NotificationList, no query of its own).
     *
     * @return list<Notification>
     */
    public function toConfirm(): array
    {
        return array_values(array_filter(
            $this->notifications->items ?? [],
            fn (Notification $item) => $item->kind === Notification::ANNOUNCEMENT,
        ));
    }

    /**
     * «Ver todos» under «Por confirmar»: the bell's list stops at
     * NotificationList::LIMIT lines, so a full list may leave some out.
     */
    public function moreToConfirm(): bool
    {
        return count($this->toConfirm()) >= NotificationList::LIMIT;
    }

    public function hasApps(): bool
    {
        return $this->appCount > 0;
    }

    public function isSearching(): bool
    {
        return $this->query !== '' && $this->hasApps();
    }

    /**
     * «Buenos días, Ana» (just the salutation when the name is unknown).
     */
    public function greeting(): string
    {
        return $this->firstName === '' ? $this->salutation : "{$this->salutation}, {$this->firstName}";
    }

    public function availability(): string
    {
        return $this->appCount === 1
            ? "Tienes 1 aplicación disponible en {$this->companyName}."
            : "Tienes {$this->appCount} aplicaciones disponibles en {$this->companyName}.";
    }

    public function resultSummary(): string
    {
        return match ($this->resultCount) {
            0 => "No encontramos aplicaciones para «{$this->query}».",
            1 => '1 resultado',
            default => "{$this->resultCount} resultados",
        };
    }
}
