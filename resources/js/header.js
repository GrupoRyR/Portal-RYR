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
// - The floating «Asistente» (a[data-assistant-fab], components/assistant-fab):
//   a link to /asistente that opens the chat panel instead. Its script
//   (resources/js/assistant-panel.js) loads on the first click only; if it
//   cannot load (offline, a newer build), the link is followed.
//
// setUpHeader() takes the document, the window and the module loader so
// tests/js/header.test.mjs can run it on a fake DOM.

import { applyTheme, readTheme, storeTheme } from './theme.js';

const OPEN_MENUS = 'details[data-menu][open]';

// A module <script> rather than import(): Vite would add its preload helper
// (another request on every page) around any dynamic import.
const scriptLoader = (doc) => (url) =>
    new Promise((resolve, reject) => {
        const script = doc.createElement('script');
        script.type = 'module';
        script.src = url;
        script.onload = resolve;
        script.onerror = reject;
        doc.head.append(script);
    });

export function setUpHeader(doc, win, loadModule = scriptLoader(doc)) {
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

    // A click with a modifier (new tab, new window) stays a link. Once loaded,
    // the panel script leaves win.openPortalAssistant(fab) behind.
    doc.addEventListener('click', (event) => {
        const fab = event.target.closest?.('a[data-assistant-fab]');
        if (!fab || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        if (win.openPortalAssistant) return win.openPortalAssistant(fab);
        if (fab.hasAttribute('data-assistant-loading')) return;

        fab.setAttribute('data-assistant-loading', '');
        Promise.resolve()
            .then(() => loadModule(fab.dataset.module))
            .then(() => win.openPortalAssistant(fab))
            .catch(() => win.location.assign(fab.getAttribute('href')))
            .finally(() => fab.removeAttribute('data-assistant-loading'));
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
