<?php

namespace App\View\Components;

use App\Catalog\AppCatalog;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use App\Settings\PortalSettings;
use App\View\Home\HomePage;
use App\View\Home\HomePageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The glass top bar of the portal (odd/tasks/liquid-glass.md G3), shared by
 * the home screen and the secondary pages of a signed-in account: the
 * company logo on its plate, «Portal RYR», «Cambiar de empresa»
 * (AccountSwitcher), the search, «Soporte TI», a `bell` slot (G4) and the
 * user menu (its links, «Tus empresas», the theme choice and «Cerrar
 * sesión»).
 *
 * The home screen passes its HomePage, so the header asks for nothing more
 * (home query budget) and gets the «Secciones» menu and the «Ctrl K»
 * search of resources/js/home.js. Elsewhere it reads the signed-in user
 * with the same rules as App\View\Home\HomePageBuilder (PortalHeaderTest
 * covers each one on a secondary page): the search is then a server search
 * of the home screen (`/?q=`), shown only when the user has apps.
 */
class PortalHeader extends Component
{
    public readonly string $userName;

    public readonly string $userInitials;

    public readonly ?string $userEmail;

    public readonly string $companyName;

    public readonly bool $canAdminister;

    public readonly ?string $supportHref;

    public readonly ?string $accountsHref;

    public readonly ?string $birthdayHref;

    /** @var array{current: string, targets: list<array{id: int, label: string}>, menu: bool}|null */
    public readonly ?array $switcher;

    /** @var list<array{href: string, label: string, offline?: false}> */
    public readonly array $sections;

    /** The search field; the home screen hides it without apps. */
    public readonly bool $hasSearch;

    /** The «Ctrl K» button (the home screen's search dialog). */
    public readonly bool $hasSearchDialog;

    public readonly string $query;

    public function __construct(public readonly ?HomePage $page = null)
    {
        if ($page !== null) {
            $this->userName = $page->userName;
            $this->userInitials = $page->userInitials;
            $this->userEmail = $page->userEmail;
            $this->companyName = $page->companyName;
            $this->canAdminister = $page->canAdminister;
            $this->supportHref = $page->supportHref;
            $this->accountsHref = $page->accountsHref;
            $this->birthdayHref = $page->birthdayHref;
            $this->switcher = $page->switcher;
            $this->sections = $page->sections();
            $this->hasSearch = $page->hasApps();
            $this->hasSearchDialog = $page->searchIndex !== null;
            $this->query = $page->query;

            return;
        }

        $user = Auth::user();
        $user = $user instanceof User ? $user : null;

        $this->userName = (string) $user?->name;
        $this->userInitials = self::initials($this->userName);
        $this->userEmail = $user?->email;
        $this->companyName = Company::displayName($user?->company);
        $this->canAdminister = $user?->hasAnyRole(HomePageBuilder::ADMIN_ROLES) ?? false;
        $this->supportHref = app(PortalSettings::class)->supportUrl() === null ? null : route('support');
        $this->accountsHref = $user === null || $user->is_emergency ? null : route('account.show');
        $this->birthdayHref = DirectoryEntry::companyIdFor($user) === null ? null : route('account.show').'#cumpleanos';
        $this->switcher = $user === null ? null : AccountSwitcher::for($user);
        $this->sections = [];
        $this->hasSearch = $user !== null && app(AppCatalog::class)->visibleApps($user)->isNotEmpty();
        $this->hasSearchDialog = false;
        $this->query = '';
    }

    /**
     * Up to two initials for the avatar (as App\View\Home\HomePageBuilder).
     */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::upper(implode('', array_map(
            fn (string $word) => mb_substr($word, 0, 1),
            array_slice($words, 0, 2),
        )));
    }

    public function render(): View
    {
        return view('components.portal-header');
    }
}
