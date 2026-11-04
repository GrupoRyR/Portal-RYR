// resources/js/assistant-panel.js under Node's own runner, on the fake DOM of
// fake-dom.mjs, with a fake fetch answering like AssistantController in JSON
// (App\View\Assistant\ConversationJson): opening, the first read of the
// conversation, the turns, the draft as text, the errors and Escape.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FakeDocument } from './fake-dom.mjs';
import { openAssistant } from '../../resources/js/assistant-panel.js';

const RESTARTED = 'Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.';

const conversation = (overrides = {}) => ({
    turns: [],
    draft: null,
    remaining: 40,
    error: null,
    full: false,
    budget_reached: false,
    available: true,
    greeting: { first_name: 'Jhon', company: 'Segurex', role: 'Gerencia' },
    restarted: false,
    daily_questions: 40,
    max_length: 1000,
    ...overrides,
});

const answered = (overrides = {}) => ({
    turns: [
        { role: 'user', html: 'Hola' },
        { role: 'assistant', html: 'Hola, <a href="/anuncios">mira los anuncios</a>.' },
    ],
    draft: null,
    remaining: 39,
    error: null,
    full: false,
    budget_reached: false,
    ...overrides,
});

/**
 * A page with the floating button and a fetch that answers, in order, each
 * of $responses: {status, body}, an Error (the network failed) or a function
 * returning a promise of either (to hold a request pending).
 */
function page(...responses) {
    const doc = new FakeDocument();
    const fab = doc.element(
        'a',
        {
            href: '/asistente',
            'data-assistant-fab': '',
            'data-conversation-url': '/asistente/conversacion',
            'data-ask-url': '/asistente',
            'data-clear-url': '/asistente/nueva',
        },
        'Asistente',
    );
    doc.documentElement.append(doc.element('head', {}, doc.element('meta', { name: 'csrf-token', content: 'token-1' })), doc.element('body', {}, fab));

    const requests = [];
    const win = {
        fetch: async (url, init = {}) => {
            requests.push({ url, ...init });
            let next = responses.shift();
            if (typeof next === 'function') next = await next();
            if (next instanceof Error) throw next;
            return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body };
        },
    };

    const $ = (selector) => doc.querySelector(selector);
    const $$ = (selector) => doc.querySelectorAll(selector);

    return { doc, fab, win, requests, $, $$, open: () => openAssistant(doc, win, fab) };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function opened(...responses) {
    const view = page(...responses);
    view.open();
    await settle();
    return view;
}

function ask(view, question) {
    view.$('input[data-assistant-input]').value = question;
    return view.doc.dispatch('submit', view.$('form[data-assistant-form]'));
}

test('nothing is read until the panel opens', () => {
    const { requests, $ } = page();

    assert.equal(requests.length, 0);
    assert.equal($('[data-assistant-panel]'), null);
});

test('opening shows the panel, moves the focus in and reads the conversation once', async () => {
    const view = page({ status: 200, body: conversation() });
    view.open();

    const panel = view.$('[data-assistant-panel]');
    assert.equal(panel.getAttribute('role'), 'dialog');
    assert.equal(panel.hidden, false);
    assert.equal(view.fab.getAttribute('aria-expanded'), 'true');
    assert.equal(view.doc.activeElement, view.$('input[data-assistant-input]'));
    assert.equal(view.requests.length, 1);
    assert.equal(view.requests[0].url, '/asistente/conversacion');
    assert.equal(view.requests[0].method, 'GET');
    assert.equal(view.requests[0].headers.Accept, 'application/json');

    await settle();

    assert.equal(view.$('[data-assistant-subtitle]').textContent, 'Gerencia · Segurex');
    assert.match(view.$('[data-assistant-greeting]').textContent, /^Hola, Jhon\. Estás en Segurex con perfil de gerencia\./);
    assert.equal(view.$$('button[data-assistant-suggestion]').length, 5);
    assert.match(view.$('[data-assistant-hint]').textContent, /Te quedan 40 preguntas de 40 por hoy/);

    view.doc.dispatch('click', view.$('button[data-assistant-close]'));
    view.open();
    await settle();

    assert.equal(view.requests.length, 1);
});

test('turns are the server HTML, each marked as the person or the assistant', async () => {
    const view = await opened({
        status: 200,
        body: conversation({ turns: [{ role: 'user', html: '¿Qué hay &lt;hoy&gt;?' }, { role: 'assistant', html: 'Hay <a href="/anuncios">anuncios</a>.' }] }),
    });

    const [question, answer] = view.$$('[data-assistant-turn]');
    assert.equal(question.getAttribute('data-assistant-turn'), 'user');
    assert.equal(question.querySelector('[data-assistant-html]').innerHTML, '¿Qué hay &lt;hoy&gt;?');
    assert.equal(answer.getAttribute('data-assistant-turn'), 'assistant');
    assert.equal(answer.querySelector('[data-assistant-html]').innerHTML, 'Hay <a href="/anuncios">anuncios</a>.');
    // A conversation under way offers no suggestions.
    assert.equal(view.$$('button[data-assistant-suggestion]').length, 0);
});

test('the draft is inserted as text, with a link to review it on /asistente only', async () => {
    const view = await opened({
        status: 200,
        body: conversation({
            turns: answered().turns,
            draft: { title: '<img src=x onerror=alert(1)>', excerpt: '<b>Hoy</b> a las 3 p. m.', review_url: '/asistente#borrador' },
        }),
    });

    const draft = view.$('[data-assistant-draft]');
    assert.match(draft.textContent, /<img src=x onerror=alert\(1\)>/);
    assert.match(draft.textContent, /<b>Hoy<\/b> a las 3 p\. m\./);
    assert.doesNotMatch(draft.innerHTML, /<img|<b>/);

    const review = draft.querySelector('a[data-assistant-review]');
    assert.equal(review.getAttribute('href'), '/asistente#borrador');
    assert.equal(review.textContent, 'Revisar y publicar');
    // Publishing stays on /asistente.
    assert.equal(draft.querySelector('form, button'), null);
});

test('a review link off the portal falls back to /asistente', async () => {
    for (const url of ['javascript:alert(1)', '//evil.example/x', 'https://evil.example/']) {
        const view = await opened({ status: 200, body: conversation({ draft: { title: 'T', excerpt: 'E', review_url: url } }) });

        assert.equal(view.$('a[data-assistant-review]').getAttribute('href'), '/asistente#borrador', url);
    }
});

test('a question is sent as JSON with the CSRF token, and the answer is announced', async () => {
    let release;
    const view = await opened({ status: 200, body: conversation() }, () => new Promise((resolve) => { release = resolve; }));

    const event = ask(view, '  Hola  ');
    await settle();

    assert.equal(event.defaultPrevented, true);
    const request = view.requests[1];
    assert.equal(request.url, '/asistente');
    assert.equal(request.method, 'POST');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'token-1');
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(request.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(request.body), { pregunta: 'Hola' });

    // Pending: the question shows, the send button waits, a second one is not sent.
    const send = view.$('button[data-assistant-send]');
    assert.equal(send.disabled, true);
    assert.equal(view.$('[data-assistant-turn="user"]').querySelector('[data-assistant-html]').textContent, 'Hola');
    assert.ok(view.$('[data-assistant-pending]'));
    ask(view, 'Otra');
    await settle();
    assert.equal(view.requests.length, 2);

    release({ status: 200, body: answered() });
    await settle();

    assert.equal(send.disabled, false);
    assert.equal(view.$('[data-assistant-pending]'), null);
    // Typed while it waited: not the question sent, so it stays.
    assert.equal(view.$('input[data-assistant-input]').value, 'Otra');
    assert.equal(view.$$('[data-assistant-turn]').length, 2);
    assert.match(view.$('[data-assistant-live]').textContent, /Hola, mira los anuncios\./);
    assert.match(view.$('[data-assistant-hint]').textContent, /Te quedan 39 preguntas de 40/);
});

test('the question sent leaves the field once answered', async () => {
    const view = await opened({ status: 200, body: conversation() }, { status: 200, body: answered() });

    ask(view, 'Hola ');
    await settle();

    assert.equal(view.$('input[data-assistant-input]').value, '');
});

test('a suggestion asks it', async () => {
    const view = await opened({ status: 200, body: conversation() }, { status: 200, body: answered() });

    const suggestion = view.$('button[data-assistant-suggestion]');
    view.doc.dispatch('click', suggestion);
    await settle();

    assert.deepEqual(JSON.parse(view.requests[1].body), { pregunta: suggestion.textContent });
    assert.equal(view.doc.activeElement, view.$('input[data-assistant-input]'));
});

test('a refused question shows the server message, keeps the question and reads the conversation again', async () => {
    const message = 'Llegaste al límite de 40 preguntas de hoy. Mañana puedes seguir.';
    const view = await opened(
        { status: 200, body: conversation() },
        { status: 429, body: { error: message } },
        { status: 200, body: conversation({ remaining: 0 }) },
    );

    ask(view, 'Hola');
    await settle();
    await settle();

    const notice = view.$('[data-assistant-notice]');
    assert.equal(notice.hidden, false);
    assert.equal(notice.getAttribute('role'), 'alert');
    assert.equal(notice.textContent, message);
    assert.equal(view.$('input[data-assistant-input]').value, 'Hola');
    assert.equal(view.$$('[data-assistant-turn]').length, 0);
    // The re-read closes the field: no question left today.
    assert.equal(view.requests.length, 3);
    assert.equal(view.requests[2].url, '/asistente/conversacion');
    assert.equal(view.$('button[data-assistant-send]').disabled, true);
    assert.equal(view.$('input[data-assistant-input]').disabled, true);
    assert.equal(view.doc.activeElement, view.$('button[data-assistant-close]'));
});

test('a full conversation (422) is read again and closes the field', async () => {
    const message = 'Esta conversación está llena. Empieza una «Nueva conversación» para seguir preguntando.';
    const view = await opened(
        { status: 200, body: conversation({ turns: answered().turns }) },
        { status: 422, body: { error: message } },
        { status: 200, body: conversation({ turns: answered().turns, full: true }) },
    );

    ask(view, 'Otra');
    await settle();
    await settle();

    assert.equal(view.$('[data-assistant-notice]').textContent, message);
    assert.equal(view.$('input[data-assistant-input]').disabled, true);
    assert.match(view.$('[data-assistant-hint]').textContent, /Nueva conversación/);
});

test('what the person types while an answer is pending is kept', async () => {
    let release;
    const view = await opened({ status: 200, body: conversation() }, () => new Promise((resolve) => { release = resolve; }));

    ask(view, 'Hola');
    await settle();
    view.$('input[data-assistant-input]').value = 'Y mañana';
    release({ status: 200, body: answered() });
    await settle();

    assert.equal(view.$('input[data-assistant-input]').value, 'Y mañana');
});

test('a failed question leaves new text in the field alone', async () => {
    let release;
    const view = await opened({ status: 200, body: conversation() }, () => new Promise((resolve) => { release = resolve; }));

    ask(view, 'Hola');
    await settle();
    view.$('input[data-assistant-input]').value = 'Otra cosa';
    release(new Error('offline'));
    await settle();

    assert.equal(view.$('input[data-assistant-input]').value, 'Otra cosa');
    assert.match(view.$('[data-assistant-notice]').textContent, /No pudimos conectar/);
});

test('sending keeps the focus in the panel', async () => {
    const view = await opened({ status: 200, body: conversation() }, () => new Promise(() => {}));
    const send = view.$('button[data-assistant-send]');
    send.focus();

    ask(view, 'Hola');
    await settle();

    assert.equal(send.disabled, true);
    assert.equal(view.doc.activeElement, view.$('input[data-assistant-input]'));
});

test('a restarted conversation (409) is read again', async () => {
    const view = await opened(
        { status: 200, body: conversation({ turns: answered().turns }) },
        { status: 409, body: { error: RESTARTED } },
        { status: 200, body: conversation() },
    );

    ask(view, 'Otra');
    await settle();
    await settle();

    assert.equal(view.requests.length, 3);
    assert.equal(view.requests[2].url, '/asistente/conversacion');
    assert.equal(view.$('[data-assistant-notice]').textContent, RESTARTED);
    assert.equal(view.$$('[data-assistant-turn]').length, 0);
});

test('a restarted conversation that cannot be read again says why', async () => {
    const view = await opened(
        { status: 200, body: conversation({ turns: answered().turns }) },
        { status: 409, body: { error: RESTARTED } },
        new Error('offline'),
    );

    ask(view, 'Otra');
    await settle();
    await settle();

    assert.match(view.$('[data-assistant-notice]').textContent, /No pudimos conectar/);
});

test('a network failure or an unexpected answer gets a generic message', async () => {
    const cases = [
        [new Error('offline'), /No pudimos conectar con el asistente/],
        [{ status: 500, body: null }, /El asistente no pudo responder/],
        [{ status: 419, body: { message: 'CSRF token mismatch.' } }, /Recarga la página/],
        [{ status: 404, body: null }, /no está disponible/],
        // The throttle's own 429 («Nueva conversación») has no `error`.
        [{ status: 429, body: { message: 'Too Many Attempts.' } }, /Estás preguntando muy rápido/],
    ];

    for (const [response, expected] of cases) {
        const view = await opened({ status: 200, body: conversation() }, response);
        ask(view, 'Hola');
        await settle();

        assert.match(view.$('[data-assistant-notice]').textContent, expected);
    }
});

test('a failed first read says so and the next opening tries again', async () => {
    const view = await opened(new Error('offline'), { status: 200, body: conversation() });

    assert.match(view.$('[data-assistant-notice]').textContent, /No pudimos conectar/);
    assert.equal(view.$('button[data-assistant-send]').disabled, true);

    view.doc.dispatch('keydown', view.$('input[data-assistant-input]'), { key: 'Escape' });
    view.open();
    await settle();

    assert.equal(view.requests.length, 2);
    assert.equal(view.$('[data-assistant-notice]').hidden, true);
    assert.equal(view.$('button[data-assistant-send]').disabled, false);
});

test('a full conversation, the month budget or the daily cap close the input', async () => {
    const cases = [
        [{ full: true }, /Nueva conversación/],
        [{ budget_reached: true }, /límite de uso de este mes/],
        [{ remaining: 0 }, /Llegaste al límite de 40 preguntas de hoy/],
    ];

    for (const [state, expected] of cases) {
        const view = await opened({ status: 200, body: conversation({ turns: answered().turns, ...state }) });

        assert.equal(view.$('input[data-assistant-input]').disabled, true);
        assert.equal(view.$('button[data-assistant-send]').disabled, true);
        assert.match(view.$('[data-assistant-hint]').textContent, expected);

        ask(view, 'Hola');
        await settle();
        assert.equal(view.requests.length, 1);
    }
});

test('without the API key the panel says it is not available', async () => {
    const view = await opened({ status: 200, body: conversation({ available: false }) });

    assert.match(view.$('[data-assistant-log]').textContent, /El asistente no está disponible en este momento/);
    assert.equal(view.$('form[data-assistant-form]').hidden, true);
    assert.equal(view.$('button[data-assistant-clear]').hidden, true);
    assert.equal(view.$$('button[data-assistant-suggestion]').length, 0);
    assert.equal(view.doc.activeElement, view.$('button[data-assistant-close]'));
});

test('«Nueva conversación» clears it on the server and offers the suggestions again', async () => {
    const view = await opened(
        { status: 200, body: conversation({ turns: answered().turns }) },
        { status: 200, body: { ...answered({ turns: [] }), status: 'Empezaste una conversación nueva.' } },
    );

    view.doc.dispatch('click', view.$('button[data-assistant-clear]'));
    await settle();

    const request = view.requests[1];
    assert.equal(request.url, '/asistente/nueva');
    assert.equal(request.method, 'POST');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'token-1');
    assert.equal(view.$$('[data-assistant-turn]').length, 0);
    assert.equal(view.$$('button[data-assistant-suggestion]').length, 5);
    assert.equal(view.$('[data-assistant-live]').textContent, 'Empezaste una conversación nueva.');
});

test('«Nueva conversación» keeps the focus in the panel and maps a bare 429', async () => {
    let release;
    const view = await opened({ status: 200, body: conversation({ turns: answered().turns }) }, () => new Promise((resolve) => { release = resolve; }));
    const clear = view.$('button[data-assistant-clear]');
    clear.focus();

    view.doc.dispatch('click', clear);
    await settle();

    assert.equal(clear.disabled, true);
    assert.equal(view.doc.activeElement, view.$('input[data-assistant-input]'));

    release({ status: 429, body: { message: 'Too Many Attempts.' } });
    await settle();

    assert.equal(view.$('[data-assistant-notice]').textContent, 'Estás preguntando muy rápido. Espera un momento.');
});

test('the panel is a modal dialog that keeps Tab inside', async () => {
    const view = await opened({ status: 200, body: conversation() });
    const panel = view.$('[data-assistant-panel]');
    const clear = view.$('button[data-assistant-clear]');
    const send = view.$('button[data-assistant-send]');
    const input = view.$('input[data-assistant-input]');

    assert.equal(panel.getAttribute('aria-modal'), 'true');

    input.value = 'Hola';
    send.focus();
    const forward = view.doc.dispatch('keydown', send, { key: 'Tab' });
    assert.equal(forward.defaultPrevented, true);
    assert.equal(view.doc.activeElement, clear);

    const back = view.doc.dispatch('keydown', clear, { key: 'Tab', shiftKey: true });
    assert.equal(back.defaultPrevented, true);
    assert.equal(view.doc.activeElement, send);

    // Inside the panel, Tab moves on its own.
    input.focus();
    assert.equal(view.doc.dispatch('keydown', input, { key: 'Tab' }).defaultPrevented, false);
});

test('reopening shows the latest turn', async () => {
    const view = await opened({ status: 200, body: conversation({ turns: answered().turns }) });
    const log = view.$('[data-assistant-log]');

    view.doc.dispatch('click', view.$('button[data-assistant-close]'));
    log.scrollTop = 0;
    log.scrollHeight = 640;
    view.open();

    assert.equal(log.scrollTop, 640);
});

test('Escape and the close button close the panel and return to the button', async () => {
    const view = await opened({ status: 200, body: conversation() });
    const panel = view.$('[data-assistant-panel]');

    view.doc.dispatch('keydown', view.$('input[data-assistant-input]'), { key: 'Escape' });

    assert.equal(panel.hidden, true);
    assert.equal(view.fab.hidden, false);
    assert.equal(view.fab.getAttribute('aria-expanded'), 'false');
    assert.equal(view.doc.activeElement, view.fab);

    view.open();
    view.doc.dispatch('click', view.$('button[data-assistant-close]'));

    assert.equal(panel.hidden, true);
    assert.equal(view.doc.activeElement, view.fab);
});

test('Escape leaves an open header menu to header.js', async () => {
    const view = await opened({ status: 200, body: conversation() });
    const menu = view.doc.element('details', { 'data-menu': '', open: '' });
    view.doc.body.append(menu);

    view.doc.dispatch('keydown', menu, { key: 'Escape' });

    assert.equal(view.$('[data-assistant-panel]').hidden, false);
});
