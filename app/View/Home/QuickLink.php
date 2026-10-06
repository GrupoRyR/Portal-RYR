<?php

namespace App\View\Home;

/**
 * A shortcut in the Microsoft 365 panel. `icon` is one of outlook, teams,
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
