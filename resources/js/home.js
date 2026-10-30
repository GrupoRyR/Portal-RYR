// Home screen enhancements, plain JavaScript (no Livewire or Alpine here).
// Everything also works without it: search is a GET form (?q=) and each
// star is a POST/DELETE form handled by FavoriteController.
//
// - Instant search over the rendered cards, with the count announced.
// - Favorite toggle without a reload: the card moves between Favoritos and
//   its category.
// - «Ctrl K» (⌘ K), "/" and the bottom bar's «Buscar» open the search
//   dialog over apps, announcements and dates; without it (a server search),
//   "/" and «Buscar» focus the search field. The bottom bar also opens the
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

// Logo entrance (resources/css/app.css, which alone holds its timing): once,
// on page load. Each card drops its flag when its own pop ends, so hover and
// focus can wiggle it from then on. A card that moves or comes back from a
// search would replay it, so every flag also goes as soon as the list changes.
const motion = window.matchMedia('(prefers-reduced-motion: no-preference)');
const endEntrance = () => $$('[data-enter]').forEach((card) => delete card.dataset.enter);

const endPop = (event) => {
    const card = event.target.closest('[data-enter]');
    if (event.animationName === 'logo-pop' && card) delete card.dataset.enter;
};
document.addEventListener('animationend', endPop);
document.addEventListener('animationcancel', endPop);

// With reduced motion no pop runs, so none would end.
if (!motion.matches) endEntrance();

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

// Today in Bogotá, the calendar timezone (App\Calendar\ColombianHolidays,
// from config app.timezone), as an ISO date: ISO dates compare as strings.
const bogotaToday = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' }).format(
        new Date(),
    );

// A date of the search index that is over: an event at the moment it ends
// (`end`, as `data-event-end` on the home list), a holiday after its day.
const isOver = (entry, today, now) => (entry.end ? Date.parse(entry.end) <= now : Boolean(entry.date) && entry.date < today);

// «Ctrl K» search (x-home.search-dialog) over the index embedded in the page
// (App\Search\HomeSearchIndex): apps, announcements, dates and people,
// matched like the server search. Absent on a server search (?q=).
const dialog = $('[data-search-dialog]');
const SEARCH_GROUP_LIMIT = 6;
// Idle status line when the dialog has no data-idle (e.g. a page cached
// before it existed).
const SEARCH_IDLE_STATUS = 'Escribe para buscar aplicaciones, anuncios y fechas.';
let found = [];
let active = -1;
let opener = null;

function selectResult(position) {
    active = position;
    found.forEach(({ option }, n) => option.setAttribute('aria-selected', String(n === position)));

    const field = $('[data-search-input]', dialog);
    if (position < 0) {
        field.removeAttribute('aria-activedescendant');
        return;
    }
    field.setAttribute('aria-activedescendant', found[position].option.id);
    found[position].option.scrollIntoView({ block: 'nearest' });
}

function renderResults(index) {
    const field = $('[data-search-input]', dialog);
    const query = field.value.trim();
    const terms = normalize(query).split(' ').filter(Boolean);
    // The saved copy can be a day old: no holiday or event that is already over.
    const today = bogotaToday();
    const now = Date.now();
    found = [];

    for (const group of $$('[data-search-group]', dialog)) {
        $$('[role="option"]', group).forEach((option) => option.remove());

        const hits =
            terms.length === 0
                ? []
                : index[group.dataset.searchGroup]
                      .filter((entry) => !isOver(entry, today, now))
                      .filter((entry) => terms.every((term) => entry.search.includes(term)))
                      .slice(0, SEARCH_GROUP_LIMIT);

        for (const entry of hits) {
            const option = $('template', group).content.firstElementChild.cloneNode(true);
            option.id = `busqueda-opcion-${found.length}`;
            if (entry.href) option.dataset.link = '';
            $('[data-title]', option).textContent = entry.title;
            $('[data-detail]', option).textContent = entry.detail;
            group.append(option);
            found.push({ option, entry });
        }

        group.hidden = hits.length === 0;
    }

    $('[data-search-results]', dialog).hidden = found.length === 0;
    field.setAttribute('aria-expanded', String(found.length > 0));
    $('[data-search-status]', dialog).textContent =
        terms.length === 0
            ? $('[data-search-status]', dialog).dataset.idle || SEARCH_IDLE_STATUS
            : found.length === 0
              ? `No encontramos resultados para «${query}».`
              : found.length === 1
                ? '1 resultado'
                : `${found.length} resultados`;
    selectResult(found.length > 0 ? 0 : -1);
}

// Holidays have no link: the result is the answer.
function openResult(position) {
    const entry = found[position]?.entry;
    if (!entry?.href) return;

    if (entry.newTab) {
        window.open(entry.href, '_blank', 'noopener');
        dialog.close();
    } else {
        window.location.assign(entry.href);
    }
}

function openSearch() {
    if (!dialog || dialog.open) return;

    opener = document.activeElement;
    dialog.showModal();
    const field = $('[data-search-input]', dialog);
    field.focus();
    field.select();
}

if (dialog) {
    const index = JSON.parse($('#search-index').textContent);
    const field = $('[data-search-input]', dialog);

    field.addEventListener('input', () => renderResults(index));
    field.addEventListener('keydown', (event) => {
        // Enter or an arrow that confirms an IME composition is not ours.
        if (event.isComposing) return;

        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && found.length > 0) {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            selectResult((active + step + found.length) % found.length);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            openResult(active);
        }
    });

    const results = $('[data-search-results]', dialog);
    const positionOf = (target) => {
        const option = target.closest('[role="option"]');
        return option ? found.findIndex((result) => result.option === option) : -1;
    };

    results.addEventListener('click', (event) => {
        const position = positionOf(event.target);
        if (position >= 0) openResult(position);
    });
    // The pointer moves the active option, so only one row ever looks
    // active; pointermove (not pointerover) leaves the arrow keys in charge
    // while the mouse rests on a row.
    results.addEventListener('pointermove', (event) => {
        const position = positionOf(event.target);
        if (position >= 0 && position !== active) selectResult(position);
    });

    // A click on the backdrop lands on the <dialog> itself.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    $('[data-search-close]', dialog).addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        opener?.focus?.();
        opener = null;
    });

    const trigger = $('[data-search-open]');
    if (trigger) {
        if (/Mac|iPhone|iPad/.test(navigator.platform)) $('[data-search-shortcut]', trigger).textContent = '⌘ K';
        trigger.hidden = false;
        trigger.addEventListener('click', openSearch);
    }
}

document.addEventListener('keydown', (event) => {
    if (event.isComposing) return;

    // Ctrl K / Cmd K works from any field, inputs included: the command
    // palette convention, and no browser text field uses it. «/» below does
    // not, since it is typed in text.
    if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k' && dialog) {
        event.preventDefault();
        if (dialog.open) dialog.close();
        else openSearch();
        return;
    }

    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;

    if (dialog) {
        event.preventDefault();
        openSearch();
    } else if (input) {
        event.preventDefault();
        input.focus();
        input.select();
    }
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

    if (trigger?.hasAttribute('data-focus-search') && dialog) {
        event.preventDefault();
        openSearch();
    } else if (trigger?.hasAttribute('data-focus-search') && input) {
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

// «Secciones» (phones): a plain <details>; this only closes it after a
// jump, on Escape and on a click elsewhere.
const sectionsMenu = $('[data-sections]');

document.addEventListener('click', (event) => {
    if (!sectionsMenu?.open) return;
    if (event.target.closest('[data-sections] a') || !sectionsMenu.contains(event.target)) {
        sectionsMenu.open = false;
    }
});

sectionsMenu?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sectionsMenu.open) {
        sectionsMenu.open = false;
        $('summary', sectionsMenu).focus();
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

// The saved copy can be up to a day old (resources/js/sw.js): drop the
// holidays and events that are over (an event at the moment it ends,
// `data-event-end`) and the «Hoy» of a day that is no longer today.
function correctSavedDates() {
    const today = bogotaToday();
    const now = Date.now();

    const holidayNotice = $('[data-holiday-notice]');
    if (holidayNotice && holidayNotice.dataset.holidayDate < today) holidayNotice.hidden = true;

    const rows = $$('li[data-holiday-date], li[data-event-end]');
    for (const row of rows) {
        const day = row.dataset.holidayDate ?? row.dataset.eventDate;
        row.hidden = row.dataset.eventEnd ? Date.parse(row.dataset.eventEnd) <= now : day < today;
        if (day === today) continue;

        $$('[data-holiday-today], [data-event-today]', row).forEach((label) => label.remove());
        $$('[data-holiday-weekday], [data-event-weekday]', row).forEach(
            (label) => (label.textContent = label.dataset.holidayWeekday ?? label.dataset.eventWeekday),
        );
    }

    const dates = $('#fechas');
    if (dates && rows.length > 0 && rows.every((row) => row.hidden)) dates.hidden = true;

    // «Cumpleaños y aniversarios»: a past day goes, and «Hoy», «Mañana» or
    // the weekday (`data-celebration-day`) follow the real today.
    const tomorrow = nextIsoDay(today);
    const celebrations = $$('li[data-celebration-date]');
    for (const row of celebrations) {
        const day = row.dataset.celebrationDate;
        row.hidden = day < today;

        const label = $('[data-celebration-day]', row);
        if (label) label.textContent = day === today ? 'Hoy' : day === tomorrow ? 'Mañana' : label.dataset.celebrationDay;
    }

    const celebrationList = $('#celebraciones');
    if (celebrationList && celebrations.length > 0 && celebrations.every((row) => row.hidden)) celebrationList.hidden = true;
}

// The ISO date after an ISO date (noon UTC, so no zone shifts the day).
const nextIsoDay = (iso) => {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 1);
    return date.toISOString().slice(0, 10);
};

if (fromCache) correctSavedDates();

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
