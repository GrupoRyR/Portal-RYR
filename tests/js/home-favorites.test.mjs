// The favorite star of resources/js/home.js under Node's own runner, on the
// fake DOM of fake-dom.mjs (odd/tasks/vistas-apps.md V3). In «Categorías»
// the card moves to Favoritos and back to its category; in the flat views
// (App\View\Home\AppsView: «Todas», «Lista», «Íconos») it keeps its place
// from A to Z. Either way the star is pressed without a reload.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeDocument } from './fake-dom.mjs';

const doc = new FakeDocument();
const body = doc.element('body', { 'data-server-search': 'false', 'data-pwa-source': 'network' });
doc.documentElement.append(body);

const requests = [];
let answer = {};

// home.js reads the page's globals when it loads, so they come first.
globalThis.document = doc;
globalThis.window = { matchMedia: () => ({ matches: false }), addEventListener() {} };
globalThis.fetch = async (url, options) => {
    requests.push({ url, method: options.method });
    return { ok: true, json: async () => answer };
};

await import('../../resources/js/home.js');

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** One card as x-home.app-card renders it, with its star form. */
function card(slug, { category = 'ventas', position = 0, favorite = false } = {}) {
    const token = doc.element('input', { type: 'hidden', name: '_token', value: 'token' });
    const method = doc.element('input', { type: 'hidden', name: '_method', value: favorite ? 'DELETE' : 'POST' });
    const button = doc.element('button', { type: 'submit', 'aria-pressed': String(favorite) }, doc.element('svg', {}));
    const form = doc.element('form', { method: 'POST', action: `/favoritos/${slug}`, 'data-favorite': '' }, token, method, button);
    token.value = 'token';
    method.value = favorite ? 'DELETE' : 'POST';
    form.action = `/favoritos/${slug}`;
    form.elements = { _token: token, _method: method };
    form.submitted = 0;
    form.submit = () => form.submitted++;

    const item = doc.element('li', { id: `app-${slug}`, 'data-app': slug, 'data-category': category, 'data-position': String(position) }, form);

    return { item, form, button, method };
}

const slugs = (list) => list.children.map((item) => item.getAttribute('data-app'));

async function press(star) {
    requests.length = 0;
    doc.dispatch('submit', star.form);
    await settle();
}

test('in «Categorías» a pinned card moves to Favoritos and back to its place', async () => {
    const portafolio = card('portafolio', { position: 0 });
    const pedidos = card('toma-pedidos', { position: 1 });
    const favorites = doc.element('ul', { 'data-list': '' });
    const ventas = doc.element('ul', { 'data-list': '' }, portafolio.item, pedidos.item);
    body.replaceChildren(
        doc.element('section', { id: 'favoritos', 'data-section': '' }, favorites),
        doc.element('section', { 'data-section': '', 'data-category': 'ventas' }, ventas),
    );

    answer = { favorite: true };
    await press(portafolio);

    assert.deepEqual(requests, [{ url: '/favoritos/portafolio', method: 'POST' }]);
    assert.deepEqual(slugs(favorites), ['portafolio']);
    assert.deepEqual(slugs(ventas), ['toma-pedidos']);
    assert.equal(portafolio.button.getAttribute('aria-pressed'), 'true');
    assert.equal(portafolio.method.value, 'DELETE');

    answer = { favorite: false };
    await press(portafolio);

    assert.deepEqual(requests, [{ url: '/favoritos/portafolio', method: 'DELETE' }]);
    assert.deepEqual(slugs(favorites), []);
    assert.deepEqual(slugs(ventas), ['portafolio', 'toma-pedidos']);
    assert.equal(portafolio.form.submitted, 0);
});

test('in a flat view the card keeps its place from A to Z', async () => {
    const bancos = card('bancos', { category: 'tesoreria', position: 2 });
    const cartera = card('cartera', { category: 'tesoreria', position: 1 });
    const portafolio = card('portafolio', { position: 0 });
    const list = doc.element('ul', { id: 'aplicaciones', 'data-list': '' }, bancos.item, cartera.item, portafolio.item);
    body.replaceChildren(list);

    answer = { favorite: true };
    await press(cartera);

    assert.deepEqual(requests, [{ url: '/favoritos/cartera', method: 'POST' }]);
    assert.deepEqual(slugs(list), ['bancos', 'cartera', 'portafolio']);
    assert.equal(cartera.button.getAttribute('aria-pressed'), 'true');
    assert.equal(cartera.method.value, 'DELETE');
    // No fallback to the plain form: it would send the star a second time.
    assert.equal(cartera.form.submitted, 0);

    answer = { favorite: false };
    await press(cartera);

    assert.deepEqual(requests, [{ url: '/favoritos/cartera', method: 'DELETE' }]);
    assert.deepEqual(slugs(list), ['bancos', 'cartera', 'portafolio']);
    assert.equal(cartera.button.getAttribute('aria-pressed'), 'false');
    assert.equal(cartera.method.value, 'POST');
    assert.equal(cartera.form.submitted, 0);
});
