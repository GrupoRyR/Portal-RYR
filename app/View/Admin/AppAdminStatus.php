<?php

namespace App\View\Admin;

use App\Models\App;
use Illuminate\Database\Eloquent\Builder;

/**
 * The status an administrator reads for an app: what TI set (hidden,
 * maintenance) first, then failures reported by an external system.
 * Maintenance wins over a reported failure (brief §6b).
 */
enum AppAdminStatus: string
{
    case Active = 'activa';
    case Failing = 'fallas';
    case Maintenance = 'mantenimiento';
    case Hidden = 'oculta';

    public static function of(App $app): self
    {
        return match (true) {
            $app->status === App::STATUS_HIDDEN => self::Hidden,
            $app->inMaintenance() => self::Maintenance,
            $app->hasReportedFailure() => self::Failing,
            default => self::Active,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Failing => 'Con fallas',
            self::Maintenance => 'En mantenimiento',
            self::Hidden => 'Oculta',
        };
    }

    /**
     * Restricts an app query to this status (same rules as {@see of()}).
     *
     * @param  Builder<App>  $query
     */
    public function scope(Builder $query): void
    {
        match ($this) {
            self::Hidden => $query->where('status', App::STATUS_HIDDEN),
            self::Maintenance => $query->where('status', App::STATUS_MAINTENANCE),
            self::Failing => $query->where('status', App::STATUS_ACTIVE)->reportedFailing(),
            self::Active => $query->where('status', App::STATUS_ACTIVE)->notReportedFailing(),
        };
    }
}
