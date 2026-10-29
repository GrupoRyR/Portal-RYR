<?php

namespace App\View\Components;

use App\Auth\LinkedAccounts;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * «Cambiar de empresa» in the header band: the company of the signed-in
 * account and the person's other accounts it may switch to
 * (App\Auth\LinkedAccounts::switchTargets()). Each other company is a POST
 * to `account.switch`; the switch itself is a Microsoft sign-in with that
 * account. Inline buttons up to three companies in total, a <details>
 * menu from {@see MENU_FROM}: no JavaScript either way.
 *
 * Hidden for emergency accounts and when there is nothing to switch to.
 * Companies are named in text (their short name), never with their
 * logos: the band carries one company's brand only (brand manual).
 *
 * The home screen passes the data built by App\View\Home\HomePageBuilder;
 * without it (components/brand-bar) the component asks for the signed-in
 * user's.
 */
class AccountSwitcher extends Component
{
    /** From this many companies in total, a menu instead of buttons. */
    public const MENU_FROM = 4;

    /**
     * @var array{current: string, targets: list<array{id: int, label: string}>, menu: bool}|null
     */
    public readonly ?array $switcher;

    /**
     * @param  array{current: string, targets: list<array{id: int, label: string}>, menu: bool}|null  $switcher  From {@see for()}.
     */
    public function __construct(?array $switcher = null)
    {
        $user = Auth::user();

        $this->switcher = $switcher ?? ($user instanceof User ? self::for($user) : null);
    }

    /**
     * What the switcher shows to this user; null hides it.
     *
     * @return array{current: string, targets: list<array{id: int, label: string}>, menu: bool}|null
     */
    public static function for(User $user): ?array
    {
        $targets = app(LinkedAccounts::class)->switchTargets($user);

        if ($targets->isEmpty()) {
            return null;
        }

        return [
            'current' => self::label($user->company),
            'targets' => $targets
                ->map(fn (User $target) => ['id' => (int) $target->getKey(), 'label' => self::label($target->company)])
                ->all(),
            'menu' => $targets->count() + 1 >= self::MENU_FROM,
        ];
    }

    /**
     * The short name, or the full name when there is none.
     */
    private static function label(?Company $company): string
    {
        return $company?->short_name ?: Company::displayName($company);
    }

    public function shouldRender(): bool
    {
        return $this->switcher !== null;
    }

    public function render(): View
    {
        return view('components.account-switcher');
    }
}
