<?php

namespace App\View\Home;

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
     * @param  list<HolidayDay>  $upcomingHolidays  «Próximas fechas», from today: see HomePageBuilder::UPCOMING_HOLIDAY_LIMIT and UPCOMING_HOLIDAY_DAYS.
     * @param  HolidayDay|null  $nextHoliday  Today's holiday or the next one, beside the date line.
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
     * The «Secciones» menu of phones: only sections the view renders, as
     * anchors (home.blade.php keeps the same conditions).
     *
     * @return list<array{href: string, label: string}>
     */
    public function sections(): array
    {
        $sections = [['href' => '#contenido', 'label' => 'Inicio']];

        if ($this->hasApps()) {
            $sections[] = ['href' => '#favoritos', 'label' => 'Favoritos'];

            if ($this->recents !== []) {
                $sections[] = ['href' => '#recientes', 'label' => 'Recientes'];
            }

            $sections[] = ['href' => '#aplicaciones', 'label' => 'Aplicaciones'];
        }

        if ($this->upcomingHolidays !== []) {
            $sections[] = ['href' => '#fechas', 'label' => 'Próximas fechas'];
        }

        if ($this->quickLinks !== []) {
            $sections[] = ['href' => '#m365', 'label' => 'Microsoft 365'];
        }

        return $sections;
    }

    /**
     * The announcement of the phone and tablet banner.
     */
    public function topAnnouncement(): ?AnnouncementCard
    {
        return $this->announcements[0] ?? null;
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
