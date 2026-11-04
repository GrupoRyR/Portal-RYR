// resources/js/theme.js under Node's own runner (node --test tests/js),
// run by tests/Feature/Layouts/ThemeScriptTest.php, on the fake DOM of
// fake-dom.mjs, which matches the script's selectors for real.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeDocument } from './fake-dom.mjs';
import { applyTheme, mediaFor, readTheme, storeTheme, THEME_KEY } from '../../resources/js/theme.js';

function page() {
    const doc = new FakeDocument();
    const el = {
        lightBar: doc.element('meta', { name: 'theme-color', content: '#F5F5F2', media: '(prefers-color-scheme: light)' }),
        darkBar: doc.element('meta', { name: 'theme-color', content: '#121315', media: '(prefers-color-scheme: dark)' }),
        // Not scheme-bound: left alone.
        brandBar: doc.element('meta', { name: 'theme-color', content: '#58595B' }),
        darkIcon: doc.element('source', { srcset: '/icon-dark.svg', media: '(prefers-color-scheme: dark)' }),
        wideImage: doc.element('source', { srcset: '/wide.webp', media: '(min-width: 768px)' }),
        stylesheet: doc.element('link', { rel: 'stylesheet', media: '(prefers-color-scheme: dark)' }),
        light: doc.element('button', { type: 'button', 'data-theme-choice': 'light', 'aria-pressed': 'false' }),
        dark: doc.element('button', { type: 'button', 'data-theme-choice': 'dark', 'aria-pressed': 'false' }),
        system: doc.element('button', { type: 'button', 'data-theme-choice': 'system', 'aria-pressed': 'true' }),
    };
    doc.documentElement.append(
        doc.element('head', {}, el.lightBar, el.darkBar, el.brandBar, el.stylesheet),
        doc.element('body', {}, doc.element('picture', {}, el.darkIcon, el.wideImage), el.light, el.dark, el.system),
    );

    return { doc, el };
}

const pressed = (el) => ['light', 'dark', 'system'].filter((key) => el[key].getAttribute('aria-pressed') === 'true');

test('a chosen theme turns the scheme media on or off', () => {
    assert.equal(mediaFor('(prefers-color-scheme: dark)', 'light'), 'not all');
    assert.equal(mediaFor('(prefers-color-scheme: dark)', 'dark'), 'all');
    assert.equal(mediaFor('(prefers-color-scheme: light)', 'light'), 'all');
    assert.equal(mediaFor('(prefers-color-scheme:light)', 'dark'), 'not all');
    // The system choice keeps the server value; other media are left alone.
    assert.equal(mediaFor('(prefers-color-scheme: dark)', null), '(prefers-color-scheme: dark)');
    assert.equal(mediaFor('(min-width: 768px)', 'dark'), '(min-width: 768px)');
});

test('light on a dark system: light attribute, light bar and light icons', () => {
    const { doc, el } = page();

    applyTheme(doc, 'light');

    assert.equal(doc.documentElement.getAttribute('data-theme'), 'light');
    assert.equal(el.lightBar.getAttribute('media'), 'all');
    assert.equal(el.darkBar.getAttribute('media'), 'not all');
    assert.equal(el.darkIcon.getAttribute('media'), 'not all');
    assert.deepEqual(pressed(el), ['light']);
});

test('only theme-color metas and picture sources follow the theme', () => {
    const { doc, el } = page();

    applyTheme(doc, 'dark');

    assert.equal(el.brandBar.hasAttribute('media'), false);
    assert.equal(el.wideImage.getAttribute('media'), '(min-width: 768px)');
    assert.equal(el.stylesheet.getAttribute('media'), '(prefers-color-scheme: dark)');
});

test('dark, then back to the system, restores what the server sent', () => {
    const { doc, el } = page();

    applyTheme(doc, 'dark');
    assert.equal(doc.documentElement.getAttribute('data-theme'), 'dark');
    assert.equal(el.darkBar.getAttribute('media'), 'all');
    assert.equal(el.lightBar.getAttribute('media'), 'not all');
    assert.equal(el.darkIcon.getAttribute('media'), 'all');
    assert.deepEqual(pressed(el), ['dark']);

    applyTheme(doc, null);
    assert.equal(doc.documentElement.hasAttribute('data-theme'), false);
    assert.equal(el.lightBar.getAttribute('media'), '(prefers-color-scheme: light)');
    assert.equal(el.darkBar.getAttribute('media'), '(prefers-color-scheme: dark)');
    assert.equal(el.darkIcon.getAttribute('media'), '(prefers-color-scheme: dark)');
    assert.deepEqual(pressed(el), ['system']);
});

test('only light and dark are read back from storage', () => {
    const storage = (value) => ({ getItem: (key) => (key === THEME_KEY ? value : null) });

    assert.equal(THEME_KEY, 'g-theme');
    assert.equal(readTheme(storage('dark')), 'dark');
    assert.equal(readTheme(storage('light')), 'light');
    assert.equal(readTheme(storage('purple')), null);
    assert.equal(readTheme(storage(null)), null);
    assert.equal(readTheme(null), null);
});

test('blocked storage never breaks the choice', () => {
    const blocked = {
        getItem() { throw new Error('SecurityError'); },
        setItem() { throw new Error('QuotaExceededError'); },
        removeItem() { throw new Error('SecurityError'); },
    };

    assert.equal(readTheme(blocked), null);
    assert.doesNotThrow(() => storeTheme(blocked, 'dark'));
    assert.doesNotThrow(() => storeTheme(blocked, null));
    assert.doesNotThrow(() => storeTheme(null, 'dark'));
});

test('the choice is stored, and the system choice forgets it', () => {
    const saved = new Map();
    const storage = {
        getItem: (key) => saved.get(key) ?? null,
        setItem: (key, value) => saved.set(key, value),
        removeItem: (key) => saved.delete(key),
    };

    storeTheme(storage, 'dark');
    assert.equal(saved.get('g-theme'), 'dark');

    storeTheme(storage, null);
    assert.equal(saved.has('g-theme'), false);
});
