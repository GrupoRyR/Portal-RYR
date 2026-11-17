<?php

namespace App\Management;

/**
 * What became of «Actualizar ventas y cartera» (App\Management\SapRefresh),
 * with the plain message the panel shows. Never the technical detail of
 * the API (the Bases SAP guide §2.5).
 */
enum SapRefreshOutcome
{
    /** 201 or 200 for both sets: created, or joined to one already waiting. */
    case Requested;

    /** 401 (key missing or revoked), 422 or another refusal: TI must look. */
    case Refused;

    /** 429: more than 60 requests a minute with the Portal's key. */
    case TooManyRequests;

    /** A network failure or a 5xx: the person may press again. */
    case Unreachable;

    public function message(): string
    {
        return match ($this) {
            self::Requested => 'Pedimos la actualización de ventas y cartera. Suele tardar unos minutos.',
            self::Refused => 'No pudimos pedir la actualización. Avísale a TI.',
            self::TooManyRequests => 'Hay muchas solicitudes en este momento. Inténtalo de nuevo en un minuto.',
            self::Unreachable => 'No pudimos pedir la actualización. Inténtalo de nuevo; las cifras que ves siguen siendo válidas.',
        };
    }
}
