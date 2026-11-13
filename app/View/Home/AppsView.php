<?php

namespace App\View\Home;

/**
 * The views of «Mis aplicaciones» on the home screen, one per account in
 * `users.apps_view` (App\Http\Controllers\AppsViewController); the only
 * place that knows the allowed values and the default:
 *
 * - Categories: Favoritos, Recientes, then a group per category;
 * - All: every app in one grid of cards, from A to Z;
 * - List: one row per app (icon, name, description, status), from A to Z;
 * - Launcher: icon and name only, in a dense grid, from A to Z.
 *
 * The flat views (all but Categories) neither take favorites out nor show
 * recents: every card keeps its star instead.
 */
enum AppsView: string
{
    case Categories = 'categories';
    case All = 'all';
    case List = 'list';
    case Launcher = 'launcher';

    /** What an account that never chose sees: the home as it always was. */
    public const DEFAULT = self::Categories;

    /**
     * The stored value as a view: the default when it is empty or no
     * longer offered, so an old value never breaks the home.
     */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DEFAULT;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $view) => $view->value, self::cases());
    }

    /** One list from A to Z instead of the groups. */
    public function isFlat(): bool
    {
        return $this !== self::Categories;
    }

    /** The button of the selector in the card head. */
    public function label(): string
    {
        return match ($this) {
            self::Categories => 'Categorías',
            self::All => 'Todas',
            self::List => 'Lista',
            self::Launcher => 'Íconos',
        };
    }
}
