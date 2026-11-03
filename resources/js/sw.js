// Service worker of the portal (installable app). Not a Vite entry: it is
// served at /sw.js by App\Http\Controllers\Pwa\ServiceWorkerController,
// which puts `self.PORTAL = { version, precache, maxAgeMs }` in front
// (App\Pwa\ServiceWorkerScript).
//
// - Home (`/`): network first; after NETWORK_TIMEOUT or without network,
//   the last saved copy, so the cards show on slow or missing connections.
// - Built assets (/build) and images (/storage, /icons): cache first; their
//   names change whenever their content does.
// - Every other page: network only, the offline page when it fails (an app
//   launched offline included). Nothing else is ever saved.
// - Sign-in, the API, Livewire and anything but GET are left alone.
//
// Privacy on shared devices: each web response names its user in
// X-Portal-User (App\Pwa\PortalUserKey). One home page is saved, tagged
// with that key; any response naming someone else, or "guest" (the sign-in
// page after signing out or after the session expired), drops it at once.
// A saved page older than an idle session (maxAgeMs) is never shown.
// Signing out also sends Clear-Site-Data, and the page asks for a purge.

const { version: VERSION, precache: PRECACHE, maxAgeMs: MAX_AGE } = self.PORTAL;

const STATIC = `portal-static-${VERSION}`;
const PAGES = `portal-pages-${VERSION}`;
const IMAGES = `portal-images-${VERSION}`;
const CURRENT = [STATIC, PAGES, IMAGES];

const HOME = '/';
const OFFLINE = '/offline.html';
const USER_HEADER = 'X-Portal-User';
const SAVED_AT_HEADER = 'X-Portal-Saved-At';
const GUEST = 'guest';
const NETWORK_TIMEOUT = 3000;

const BYPASS = /^\/(api\/|auth\/|livewire)/;
const IMAGE_PATHS = /^\/(storage|icons)\//;

// The message of the last account action (`data-flash`, home.blade.php)
// belongs to the response it came with: the saved copy never replays it.
// Its text is escaped by Blade, so it holds no `<`.
const FLASH = /<p\b[^>]*\bdata-flash\b[^>]*>[^<]*<\/p>\s*/g;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

// Drop the caches of previous builds.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key.startsWith('portal-') && !CURRENT.includes(key))
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

// Everything personal: the saved home page and the images it showed.
const purge = () => Promise.all([caches.delete(PAGES), caches.delete(IMAGES)]);

self.addEventListener('message', (event) => {
    if (event.data === 'purge') event.waitUntil(purge());
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || BYPASS.test(url.pathname)) return;

    if (request.mode === 'navigate') {
        navigate(event, url.pathname === HOME && url.search === '');
    } else if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, STATIC));
    } else if (request.destination === 'image' && IMAGE_PATHS.test(url.pathname)) {
        event.respondWith(cacheFirst(request, IMAGES));
    }
});

function navigate(event, isHome) {
    let remembered = Promise.resolve();
    const network = fetch(event.request).then((response) => {
        remembered = remember(response.clone(), isHome);
        return response;
    });

    // Keep the worker alive until the response is looked at (and saved),
    // even when the saved copy was answered first.
    event.waitUntil(network.then(() => remembered, () => {}));

    event.respondWith(isHome ? homePage(network) : network.catch(offlinePage));
}

async function homePage(network) {
    try {
        return await Promise.race([network, timeout(NETWORK_TIMEOUT)]);
    } catch {
        return (await savedHome()) ?? network.catch(offlinePage);
    }
}

// Track who the server says is signed in; save the home page of that user.
async function remember(response, isHome) {
    // Redirects (opaque) and errors say nothing about the user.
    const user = response.type === 'basic' ? response.headers.get(USER_HEADER) : null;
    if (!user) return;

    const saved = await caches.match(HOME, { cacheName: PAGES });
    if (saved && saved.headers.get(USER_HEADER) !== user) await purge();

    if (!isHome || !response.ok || user === GUEST) return;

    // Marked so the page knows it came from here (resources/js/home.js),
    // without the message of that one response.
    const html = (await response.text())
        .replace('data-pwa-source="network"', 'data-pwa-source="cache"')
        .replace(FLASH, '');
    const headers = new Headers(response.headers);
    headers.set(SAVED_AT_HEADER, String(Date.now()));

    const cache = await caches.open(PAGES);
    await cache.put(HOME, new Response(html, { status: 200, headers }));
}

async function savedHome() {
    const saved = await caches.match(HOME, { cacheName: PAGES });
    if (!saved) return null;

    const age = Date.now() - Number(saved.headers.get(SAVED_AT_HEADER));
    if (!(age >= 0 && age < MAX_AGE)) {
        await purge();
        return null;
    }

    return saved;
}

async function offlinePage() {
    return (await caches.match(OFFLINE, { cacheName: STATIC })) ?? Response.error();
}

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request, { cacheName });
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(cacheName);
        await cache.put(request, response.clone());
    }

    return response;
}

function timeout(ms) {
    return new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), ms));
}
