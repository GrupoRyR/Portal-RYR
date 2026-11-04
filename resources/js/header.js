// Portal header (components/portal-header), loaded on every page by
// components/layouts/app.blade.php and imported by resources/js/home.js (so
// the saved offline home has it too). Plain JavaScript: no Livewire or Alpine.
//
// - Theme choice in the user menu (resources/js/theme.js): shown only here,
//   since it does nothing without this script.
// - Header menus (<details data-menu>: the user menu, «Secciones», the
//   company menu): they work without JavaScript; this closes them on a click
//   elsewhere, after following a link inside and on Escape.

import { applyTheme, readTheme, storeTheme } from './theme.js';

const storage = (() => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
})();

applyTheme(document, readTheme(storage));

for (const group of document.querySelectorAll('[data-theme-switch]')) group.hidden = false;

document.addEventListener('click', (event) => {
    const choice = event.target.closest('[data-theme-choice]');
    if (!choice) return;

    const theme = choice.dataset.themeChoice === 'system' ? null : choice.dataset.themeChoice;
    storeTheme(storage, theme);
    applyTheme(document, theme);
});

// Capture phase: menus close before any page handler runs, so one that
// opens a menu (the home screen's «Cuenta» in its bottom bar) keeps it open.
document.addEventListener(
    'click',
    (event) => {
        for (const menu of document.querySelectorAll('details[data-menu][open]')) {
            if (!menu.contains(event.target) || event.target.closest('a[href]')) menu.open = false;
        }
    },
    true,
);

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    const menu = event.target.closest?.('details[data-menu][open]');
    if (!menu) return;

    menu.open = false;
    menu.querySelector('summary').focus();
});
