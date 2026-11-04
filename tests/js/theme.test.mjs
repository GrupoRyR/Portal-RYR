// resources/js/theme.js under Node's own runner (node --test tests/js),
// run by tests/Feature/Layouts/ThemeScriptTest.php. A tiny stand-in for the
// DOM: only what theme.js touches.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyTheme, mediaFor, readTheme, storeTheme, THEME_KEY } from '../../resources/js/theme.js';

class FakeElement {
    constructor(tag, attributes = {}) {
        this.tag = tag;
        this.attributes = new Map(Object.entries(attributes));
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }
}

function fakeDocument() {
    const elements = {
        html: new FakeElement('html'),
        lightBar: new FakeElement('meta', { name: 'theme-color', content: '#F5F5F2', media: '(prefers-color-scheme: light)' }),
        darkBar: new FakeElement('meta', { name: 'theme-color', content: '#121315', media: '(prefers-color-scheme: dark)' }),
        darkIcon: new FakeElement('source', { srcset: '/icon-dark.svg', media: '(prefers-color-scheme: dark)' }),
        light: new FakeElement('button', { 'data-theme-choice': 'light', 'aria-pressed': 'false' }),
        dark: new FakeElement('button', { 'data-theme-choice': 'dark', 'aria-pressed': 'false' }),
        system: new FakeElement('button', { 'data-theme-choice': 'system', 'aria-pressed': 'true' }),
    };
    const schemeBound = [elements.lightBar, elements.darkBar, elements.darkIcon];
    const choices = [elements.light, elements.dark, elements.system];

    return {
        elements,
        documentElement: elements.html,
        querySelectorAll(selector) {
            if (selector.includes('data-theme-choice')) return choices;
            if (selector.includes('prefers-color-scheme')) return schemeBound;
            throw new Error(`Unexpected selector ${selector}`);
        },
    };
}

const pressed = (doc) => ['light', 'dark', 'system'].filter((key) => doc.elements[key].getAttribute('aria-pressed') === 'true');

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
    const doc = fakeDocument();

    applyTheme(doc, 'light');

    assert.equal(doc.elements.html.getAttribute('data-theme'), 'light');
    assert.equal(doc.elements.lightBar.getAttribute('media'), 'all');
    assert.equal(doc.elements.darkBar.getAttribute('media'), 'not all');
    assert.equal(doc.elements.darkIcon.getAttribute('media'), 'not all');
    assert.deepEqual(pressed(doc), ['light']);
});

test('dark, then back to the system, restores what the server sent', () => {
    const doc = fakeDocument();

    applyTheme(doc, 'dark');
    assert.equal(doc.elements.html.getAttribute('data-theme'), 'dark');
    assert.equal(doc.elements.darkBar.getAttribute('media'), 'all');
    assert.equal(doc.elements.lightBar.getAttribute('media'), 'not all');
    assert.equal(doc.elements.darkIcon.getAttribute('media'), 'all');
    assert.deepEqual(pressed(doc), ['dark']);

    applyTheme(doc, null);
    assert.equal(doc.elements.html.hasAttribute('data-theme'), false);
    assert.equal(doc.elements.lightBar.getAttribute('media'), '(prefers-color-scheme: light)');
    assert.equal(doc.elements.darkBar.getAttribute('media'), '(prefers-color-scheme: dark)');
    assert.equal(doc.elements.darkIcon.getAttribute('media'), '(prefers-color-scheme: dark)');
    assert.deepEqual(pressed(doc), ['system']);
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
