<?php

namespace App\View\Home;

/**
 * A shortcut of «Accesos rápidos» on the home. `icon` is one of outlook, teams,
 * onedrive, sharepoint or link (resources/views/home/partials/icon).
 */
final readonly class QuickLink
{
    public function __construct(
        public string $label,
        public string $url,
        public string $icon,
    ) {}
}
