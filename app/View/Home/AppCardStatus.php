<?php

namespace App\View\Home;

use App\Models\App;

/**
 * The chip at the bottom of an app card. One per card, the most important
 * first: maintenance, failures reported by an external system (until they
 * expire, App\Models\App::hasReportedFailure()), installed locally, own
 * sign-in, otherwise active.
 */
enum AppCardStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Failing = 'failing';
    case OwnLogin = 'own-login';
    case Local = 'local';

    public static function of(App $app): self
    {
        return match (true) {
            $app->inMaintenance() => self::Maintenance,
            $app->hasReportedFailure() => self::Failing,
            $app->isLocal() => self::Local,
            $app->login_type === 'own' => self::OwnLogin,
            default => self::Active,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::Maintenance => 'En mantenimiento',
            self::Failing => 'Con fallas',
            self::OwnLogin => 'Inicio de sesión propio',
            self::Local => 'Instalada en tu equipo',
        };
    }
}
