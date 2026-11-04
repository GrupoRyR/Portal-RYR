// Theme choice of the portal header (Claro, Oscuro, Sistema), with no side
// effects of its own: resources/js/header.js wires it to the page, and
// tests/js/theme.test.mjs runs it under Node.
//
// - The choice lives in localStorage['g-theme'] ("light" or "dark"; none
//   means the system decides), read before the first paint by the inline
//   script of components/layouts/app.blade.php. Storage may be blocked.
// - <html data-theme> drives the CSS (`dark` variant of resources/css/app.css).
// - What only CSS cannot switch follows too: the browser bar colors
//   (<meta name="theme-color" media="(prefers-color-scheme: …)">) and the
//   dark app icons (<picture><source media="(prefers-color-scheme: dark)">).
//   Their media turn "all" or "not all"; the server value is kept in
//   data-scheme-media and comes back with «Sistema». Without JavaScript
//   they keep following the system.

export const THEME_KEY = 'g-theme';

const SCHEME = /\(\s*prefers-color-scheme\s*:\s*(light|dark)\s*\)/;
const SCHEME_BOUND = 'meta[name="theme-color"][media*="prefers-color-scheme"], source[media*="prefers-color-scheme"], [data-scheme-media]';

const isTheme = (value) => value === 'light' || value === 'dark';

/** The media of a scheme-bound element under a theme (null: the system). */
export function mediaFor(original, theme) {
    const scheme = SCHEME.exec(original ?? '');

    if (!scheme || !isTheme(theme)) return original;

    return scheme[1] === theme ? 'all' : 'not all';
}

/** "light", "dark" or null (the system). */
export function readTheme(storage) {
    try {
        const value = storage?.getItem(THEME_KEY);

        return isTheme(value) ? value : null;
    } catch {
        return null;
    }
}

export function storeTheme(storage, theme) {
    try {
        if (isTheme(theme)) storage?.setItem(THEME_KEY, theme);
        else storage?.removeItem(THEME_KEY);
    } catch {
        // Blocked storage: the choice lasts until the page closes.
    }
}

export function applyTheme(doc, theme) {
    const chosen = isTheme(theme) ? theme : null;

    if (chosen) doc.documentElement.setAttribute('data-theme', chosen);
    else doc.documentElement.removeAttribute('data-theme');

    for (const element of doc.querySelectorAll(SCHEME_BOUND)) {
        if (!element.hasAttribute('data-scheme-media')) element.setAttribute('data-scheme-media', element.getAttribute('media'));
        element.setAttribute('media', mediaFor(element.getAttribute('data-scheme-media'), chosen));
    }

    for (const button of doc.querySelectorAll('[data-theme-choice]')) {
        button.setAttribute('aria-pressed', String(button.getAttribute('data-theme-choice') === (chosen ?? 'system')));
    }
}
