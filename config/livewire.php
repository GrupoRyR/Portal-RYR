<?php

// Only the values that differ from Livewire's defaults; the rest is merged
// from vendor/livewire/livewire/config/livewire.php.
return [

    // Full-page components render inside <x-layouts.app>.
    'component_layout' => 'components.layouts.app',

    // Livewire's JavaScript is bundled by Vite in resources/js/app.js and the
    // layout prints @livewireScriptConfig, so nothing is injected.
    'inject_assets' => false,

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#17191C',
    ],

];
