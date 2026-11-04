// resources/js/header.js wiring under Node's own runner, on the fake DOM of
// fake-dom.mjs: the theme group, the theme buttons, the header menus and
// the sign-out purge of the service worker.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeDocument } from './fake-dom.mjs';
import { setUpHeader } from '../../resources/js/header.js';

function storage(initial = {}) {
    const saved = new Map(Object.entries(initial));
    return {
        saved,
        getItem: (key) => saved.get(key) ?? null,
        setItem: (key, value) => saved.set(key, value),
        removeItem: (key) => saved.delete(key),
    };
}

function serviceWorker() {
    const worker = { messages: [], unsubscribed: 0 };
    worker.container = {
        controller: { postMessage: (message) => worker.messages.push(message) },
        getRegistration: async () => ({
            pushManager: { getSubscription: async () => ({ unsubscribe: async () => { worker.unsubscribed++; } }) },
        }),
    };
    return worker;
}

function page({ stored = {}, worker = serviceWorker() } = {}) {
    const doc = new FakeDocument();
    const el = {};

    el.accountSummary = doc.element('summary', {});
    el.themeDark = doc.element('button', { type: 'button', 'data-theme-choice': 'dark', 'aria-pressed': 'false' });
    el.themeSystem = doc.element('button', { type: 'button', 'data-theme-choice': 'system', 'aria-pressed': 'true' });
    el.themeGroup = doc.element('div', { 'data-theme-switch': '', hidden: '' }, doc.element('div', { role: 'group' }, el.themeDark, el.themeSystem));
    el.accountLink = doc.element('a', { href: '/cuenta' });
    el.logout = doc.element('form', { method: 'POST', action: '/logout', 'data-logout': '' });
    el.account = doc.element('details', { id: 'cuenta', 'data-menu': '' }, el.accountSummary, doc.element('div', {}, el.accountLink, el.themeGroup, el.logout));
    el.sectionsSummary = doc.element('summary', {});
    el.sections = doc.element('details', { 'data-menu': '' }, el.sectionsSummary);
    el.other = doc.element('form', { method: 'GET', action: '/' });
    el.heading = doc.element('h1', {});
    doc.documentElement.append(doc.element('body', {}, el.sections, el.account, el.heading, el.other));

    const win = { localStorage: storage(stored), navigator: { serviceWorker: worker.container } };
    setUpHeader(doc, win);

    return { doc, el, win, worker };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('the theme choice shows up, with the stored theme applied', () => {
    const { doc, el } = page({ stored: { 'g-theme': 'dark' } });

    assert.equal(el.themeGroup.hidden, false);
    assert.equal(doc.documentElement.getAttribute('data-theme'), 'dark');
    assert.equal(el.themeDark.getAttribute('aria-pressed'), 'true');
    assert.equal(el.themeSystem.getAttribute('aria-pressed'), 'false');
});

test('a theme button applies and stores the choice', () => {
    const { doc, el, win } = page();

    doc.dispatch('click', el.themeDark);
    assert.equal(doc.documentElement.getAttribute('data-theme'), 'dark');
    assert.equal(win.localStorage.saved.get('g-theme'), 'dark');

    doc.dispatch('click', el.themeSystem);
    assert.equal(doc.documentElement.hasAttribute('data-theme'), false);
    assert.equal(win.localStorage.saved.has('g-theme'), false);
});

test('blocked storage still lets the theme change', () => {
    const doc = new FakeDocument();
    const dark = doc.element('button', { type: 'button', 'data-theme-choice': 'dark' });
    doc.documentElement.append(doc.element('body', {}, dark));
    const win = { navigator: {} };
    Object.defineProperty(win, 'localStorage', { get() { throw new Error('SecurityError'); } });

    setUpHeader(doc, win);
    doc.dispatch('click', dark);

    assert.equal(doc.documentElement.getAttribute('data-theme'), 'dark');
});

test('a click elsewhere closes an open menu, a click inside does not', () => {
    const { doc, el } = page();
    el.account.open = true;

    doc.dispatch('click', el.themeDark);
    assert.equal(el.account.open, true);

    doc.dispatch('click', el.heading);
    assert.equal(el.account.open, false);
});

test('following a link inside a menu closes it', () => {
    const { doc, el } = page();
    el.account.open = true;

    doc.dispatch('click', el.accountLink);

    assert.equal(el.account.open, false);
});

test('a page handler that opens a menu after the click keeps it open', () => {
    const { doc, el } = page();
    // As home.js does for «Perfil» in the bottom bar: a bubbling listener.
    doc.addEventListener('click', (event) => {
        if (event.target === el.heading) el.account.open = true;
    });

    doc.dispatch('click', el.heading);

    assert.equal(el.account.open, true);
});

test('Escape inside a menu closes it and returns to its summary', () => {
    const { doc, el } = page();
    el.account.open = true;

    doc.dispatch('keydown', el.accountLink, { key: 'Escape' });

    assert.equal(el.account.open, false);
    assert.equal(doc.activeElement, el.accountSummary);
});

test('Escape with focus outside closes the one open menu', () => {
    const { doc, el } = page();
    el.sections.open = true;

    doc.dispatch('keydown', el.heading, { key: 'Escape' });

    assert.equal(el.sections.open, false);
    assert.equal(doc.activeElement, el.sectionsSummary);
});

test('other keys leave the menus alone', () => {
    const { doc, el } = page();
    el.account.open = true;

    doc.dispatch('keydown', el.accountLink, { key: 'Enter' });

    assert.equal(el.account.open, true);
});

test('signing out asks the service worker to purge and drops the push subscription', async () => {
    const { doc, el, worker } = page();

    doc.dispatch('submit', el.logout);
    await settle();

    assert.deepEqual(worker.messages, ['purge']);
    assert.equal(worker.unsubscribed, 1);
});

test('other forms send nothing to the service worker', async () => {
    const { doc, el, worker } = page();

    doc.dispatch('submit', el.other);
    await settle();

    assert.deepEqual(worker.messages, []);
    assert.equal(worker.unsubscribed, 0);
});

test('signing out without a service worker or a controller does not throw', async () => {
    const without = page({ worker: { container: undefined } });
    assert.doesNotThrow(() => without.doc.dispatch('submit', without.el.logout));

    const worker = serviceWorker();
    worker.container.controller = null;
    worker.container.getRegistration = async () => { throw new Error('InvalidStateError'); };
    const uncontrolled = page({ worker });
    assert.doesNotThrow(() => uncontrolled.doc.dispatch('submit', uncontrolled.el.logout));
    await settle();
});
