<?php

namespace App\Settings;

use Illuminate\Routing\Events\RouteMatched;

/**
 * Applies the idle timeout of Configuración to the session of each web
 * request. Runs when the route is matched, before the `web` middleware
 * starts the session, so the session driver expires sessions idle for
 * longer (the database driver compares `last_activity`) and the cookie
 * gets the same lifetime. Routes outside `web` (such as /up) have no
 * session and are left alone.
 */
final class ApplySessionLifetime
{
    public function __construct(private readonly PortalSettings $settings) {}

    public function handle(RouteMatched $event): void
    {
        if (! in_array('web', $event->route->gatherMiddleware(), true)) {
            return;
        }

        config(['session.lifetime' => $this->settings->idleTimeoutMinutes()]);
    }
}
