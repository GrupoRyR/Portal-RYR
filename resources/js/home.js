// Home screen enhancements, plain JavaScript (no Livewire or Alpine here).
// Everything also works without it: search is a GET form (?q=) and each
// star is a POST/DELETE form handled by FavoriteController.
//
// - Instant search over the rendered cards, with the count announced.
// - Favorite toggle without a reload: the card moves between Favoritos and
//   its category.
// - "/" focuses the search; the bottom bar focuses search and opens the
//   account menu.
// - Animated logos: ends the entrance, pops the star when it gets pressed.
// - Installable app: registers the service worker (/sw.js, see
//   resources/js/sw.js), shows the offline notice and asks the worker to
//   forget this user's saved page when signing out.

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

// Same rules as App\Catalog\AppSearch::normalize() on the server.
const normalize = (text) =>
    text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

const input = $('#buscar');
const status = $('#resultados');
const account = $('#cuenta');
// A page already filtered by the server holds only the matches.
const serverSearch = document.body.dataset.serverSearch === 'true';

const searching = () => !serverSearch && !!input && normalize(input.value) !== '';

// Logo entrance (resources/css/app.css): once, on page load. A card that
// moves or comes back from a search would replay it, so the flag goes when
// the entrance is over or as soon as the list changes, whichever is first.
const ENTRANCE_MS = 1500;
const endEntrance = () => $$('[data-enter]').forEach((card) => delete card.dataset.enter);
setTimeout(endEntrance, ENTRANCE_MS);

const motion = window.matchMedia('(prefers-reduced-motion: no-preference)');

// iOS Safari only applies :active (the logo press) with a touch listener.
document.addEventListener('touchstart', () => {}, { passive: true });

// Hide sections without visible cards. Favoritos stays, with its hint, when
// it is merely empty and no search is running.
function refreshSections() {
    const active = searching() || serverSearch;

    for (const section of $$('[data-section]')) {
        const shown = $$('[data-app]', section).filter((card) => !card.hidden).length;
        const favorites = section.id === 'favoritos';
        section.hidden = shown === 0 && (active || !favorites);

        const hint = $('[data-favorites-hint]', section);
        if (hint) hint.hidden = shown > 0;
    }

    const recents = $('#recientes');
    if (recents) recents.hidden = active;
}

function filter() {
    const query = input.value.trim();
    const terms = normalize(query).split(' ').filter(Boolean);
    let shown = 0;
    endEntrance();

    for (const card of $$('[data-app]')) {
        card.hidden = !terms.every((term) => card.dataset.search.includes(term));
        if (!card.hidden) shown++;
    }

    refreshSections();

    // Same wording as App\View\Home\HomePage::resultSummary().
    status.textContent =
        terms.length === 0
            ? ''
            : shown === 0
              ? `No encontramos aplicaciones para «${query}».`
              : shown === 1
                ? '1 resultado'
                : `${shown} resultados`;
}

if (input && !serverSearch) {
    input.addEventListener('input', filter);
    // Enter keeps the instant results instead of reloading the page.
    input.form.addEventListener('submit', (event) => event.preventDefault());
}

document.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || !input) return;
    if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;

    event.preventDefault();
    input.focus();
    input.select();
});

// Put a card back in its category, in catalog order.
function placeInCategory(card) {
    const list = $(`section[data-category="${card.dataset.category}"] [data-list]`);
    const next = [...list.children].find((item) => +item.dataset.position > +card.dataset.position);
    list.insertBefore(card, next ?? null);
}

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-favorite]');
    if (!form) return;

    event.preventDefault();
    if (form.dataset.busy) return;

    const button = $('button', form);
    const card = form.closest('[data-app]');
    const pin = button.getAttribute('aria-pressed') !== 'true';
    form.dataset.busy = '1';

    try {
        const response = await fetch(form.action, {
            method: pin ? 'POST' : 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));

        const { favorite } = await response.json();
        button.setAttribute('aria-pressed', String(favorite));
        form.elements._method.value = favorite ? 'DELETE' : 'POST';

        endEntrance();
        if (favorite) $('#favoritos [data-list]').append(card);
        else placeInCategory(card);

        refreshSections();
        button.focus();

        if (favorite && motion.matches) {
            $('svg', button).animate([{ transform: 'scale(0.5) rotate(-30deg)' }, { transform: 'none' }], {
                duration: 450,
                easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
            });
        }
    } catch {
        // Let the plain form do it (it reloads the page).
        form.submit();
    } finally {
        delete form.dataset.busy;
    }
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-focus-search], [data-open-account]');

    if (trigger?.hasAttribute('data-focus-search') && input) {
        event.preventDefault();
        input.focus();
        input.scrollIntoView({ block: 'center' });
    } else if (trigger && account) {
        event.preventDefault();
        account.open = true;
        $('summary', account).focus();
        window.scrollTo({ top: 0 });
    } else if (account?.open && !account.contains(event.target)) {
        account.open = false;
    }
});

account?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && account.open) {
        account.open = false;
        $('summary', account).focus();
    }
});

// Offline notice (docs/diseno/PhoneDark): without network, or when the
// service worker answered with the saved copy of this page.
const notice = $('[data-offline-notice]');
const fromCache = document.body.dataset.pwaSource === 'cache';

function showConnection() {
    if (!notice) return;

    const offline = !navigator.onLine;
    notice.hidden = !offline && !fromCache;
    $('[data-offline-text]', notice).textContent = offline
        ? 'Sin conexión · mostrando tus apps guardadas'
        : 'Conexión lenta · mostrando tus apps guardadas';
}

showConnection();
window.addEventListener('online', showConnection);
window.addEventListener('offline', showConnection);

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });

    // Signing out also sends Clear-Site-Data; this covers browsers without it.
    document.addEventListener('submit', (event) => {
        if (event.target.matches('[data-logout]')) navigator.serviceWorker.controller?.postMessage('purge');
    });
}
