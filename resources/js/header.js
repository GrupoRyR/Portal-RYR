// Portal header (components/portal-header), loaded on every page by
// components/layouts/app.blade.php and imported by resources/js/home.js (so
// the saved offline home has it too). Plain JavaScript: no Livewire or Alpine.
//
// - Theme choice in the user menu (resources/js/theme.js): shown only here,
//   since it does nothing without this script.
// - Header menus (<details data-menu>: the user menu, «Secciones», the
//   company menu): they work without JavaScript; this closes them on a click
//   elsewhere, after following a link inside and on Escape.
// - «Cerrar sesión» (form[data-logout]) on any page: the service worker
//   forgets the saved pages of this user.
//
// setUpHeader() takes the document and window so tests/js/header.test.mjs
// can run it on a fake DOM.

import { applyTheme, readTheme, storeTheme } from './theme.js';

const OPEN_MENUS = 'details[data-menu][open]';

export function setUpHeader(doc, win) {
    const storage = (() => {
        try {
            return win.localStorage;
        } catch {
            return null;
        }
    })();

    applyTheme(doc, readTheme(storage));

    for (const group of doc.querySelectorAll('[data-theme-switch]')) group.hidden = false;

    doc.addEventListener('click', (event) => {
        const choice = event.target.closest?.('[data-theme-choice]');
        if (!choice) return;

        const theme = choice.dataset.themeChoice === 'system' ? null : choice.dataset.themeChoice;
        storeTheme(storage, theme);
        applyTheme(doc, theme);
    });

    // Capture phase: menus close before any page handler runs, so one that
    // opens a menu (the home screen's «Cuenta» in its bottom bar) keeps it open.
    doc.addEventListener(
        'click',
        (event) => {
            for (const menu of doc.querySelectorAll(OPEN_MENUS)) {
                if (!menu.contains(event.target) || event.target.closest?.('a[href]')) menu.open = false;
            }
        },
        true,
    );

    // The menu holding the focus, or else the only open one (focus may have
    // stayed on the page after a click on its summary).
    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        const open = doc.querySelectorAll(OPEN_MENUS);
        const menu = event.target.closest?.(OPEN_MENUS) ?? (open.length === 1 ? open[0] : null);
        if (!menu) return;

        menu.open = false;
        menu.querySelector('summary')?.focus();
    });

    // Signing out also sends Clear-Site-Data; this covers browsers without it:
    // the saved pages go, and so does this browser's phone notice subscription
    // («Avisos en el celular»; the server forgets it on logout too).
    doc.addEventListener('submit', (event) => {
        const worker = win.navigator?.serviceWorker;
        if (!worker || !event.target.matches?.('[data-logout]')) return;

        worker.controller?.postMessage('purge');
        Promise.resolve()
            .then(() => worker.getRegistration('/'))
            .then((registration) => registration?.pushManager?.getSubscription())
            .then((subscription) => subscription && subscription.unsubscribe())
            .catch(() => {});
    });
}

if (typeof document !== 'undefined') setUpHeader(document, window);
