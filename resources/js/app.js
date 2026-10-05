// Livewire 4 and its bundled Alpine are served from our own build (no CDN).
// The page sets window.livewireScriptConfig with @livewireScriptConfig, so
// Livewire waits for this explicit start.
import { Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';

Livewire.start();
