// Livewire 4 and its bundled Alpine, served from our own build (no CDN).
// Only pages that opt in (<x-layouts.app livewire>, every admin page) load
// this entry; they also print @livewireScriptConfig, so Livewire waits for
// this explicit start.
import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

Livewire.start();
