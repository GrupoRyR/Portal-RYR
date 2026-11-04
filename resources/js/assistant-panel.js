// «Asistente» chat panel (odd/tasks/liquid-glass.md G7), opened by the
// floating button (components/assistant-fab). resources/js/header.js loads
// this module on the button's first click; without JavaScript the button is
// a link to /asistente, which stays the full page (and the only place where
// a draft is published).
//
// It talks to AssistantController in JSON (App\View\Assistant\ConversationJson):
// - GET  conversation (once, on the first opening): turns, draft, greeting…
// - POST ask   {pregunta}: the new turns, or {error} with 422, 429, 409
//   (the conversation restarted: read it again) or 503. Up to ~90 s.
// - POST clear: «Nueva conversación».
// Turn `html` is safe server HTML; everything else (draft, greeting, errors)
// is inserted as text.
//
// openAssistant() takes the document and window so tests/js/assistant-panel.test.mjs
// can run it on a fake DOM; the page reaches it through window.openPortalAssistant.

const ICONS = {
    sparkle: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2 5 5 2-5 2-2 5-2-5-5-2 5-2zM19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2-6z"/></svg>',
};

// The same as /asistente (resources/views/assistant/show.blade.php).
const SUGGESTIONS = [
    '¿Qué tareas y aprobaciones tengo pendientes?',
    '¿Hay anuncios que deba leer?',
    '¿Cuáles son los próximos festivos y eventos?',
    '¿Quién cumple años esta semana?',
    '¿Está fallando algo de TI?',
];

const TEXT = {
    loading: 'Cargando la conversación…',
    thinking: 'Pensando… La respuesta puede tardar hasta un minuto y medio.',
    network: 'No pudimos conectar con el asistente. Revisa tu conexión e inténtalo de nuevo.',
    failed: 'El asistente no pudo responder. Inténtalo de nuevo en un momento.',
    session: 'Tu sesión cambió o expiró. Recarga la página para seguir.',
    off: 'El asistente no está disponible en este momento. Vuelve a intentarlo más tarde.',
    restarted: 'Empezamos una conversación nueva porque cambió tu cuenta o la configuración del asistente.',
    full: 'Esta conversación llegó a su límite. Empieza una «Nueva conversación» para seguir preguntando.',
    budget: 'El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.',
};

const panels = new WeakMap();

export function openAssistant(doc, win, fab) {
    if (!panels.has(doc)) panels.set(doc, createPanel(doc, win, fab));
    panels.get(doc).open();
}

function createPanel(doc, win, fab) {
    const urls = { conversation: fab.dataset.conversationUrl, ask: fab.dataset.askUrl, clear: fab.dataset.clearUrl };
    const state = { loaded: false, pending: false, asking: null, available: true, turns: [], draft: null, remaining: 0, daily: 0 };

    const el = (tag, attributes = {}, ...children) => {
        const node = doc.createElement(tag);
        for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
        node.append(...children);
        return node;
    };
    const icon = (name, attributes = {}) => {
        const node = el('span', { class: 'g-assist__icon', ...attributes });
        node.innerHTML = ICONS[name];
        return node;
    };

    const subtitle = el('p', { class: 'g-assist__sub', 'data-assistant-subtitle': '' });
    const clear = el('button', { type: 'button', class: 'g-btn g-assist__new', 'data-assistant-clear': '' }, 'Nueva conversación');
    const close = el('button', { type: 'button', class: 'g-x', 'aria-label': 'Cerrar el asistente', 'data-assistant-close': '' }, icon('close'));
    const log = el('div', { class: 'g-assist__log', 'data-assistant-log': '' });
    const live = el('p', { class: 'sr-only', 'aria-live': 'polite', 'data-assistant-live': '' });
    const notice = el('p', { class: 'g-assist__notice', role: 'alert', hidden: '', 'data-assistant-notice': '' });
    const input = el('input', {
        type: 'text',
        id: 'asistente-pregunta',
        name: 'pregunta',
        autocomplete: 'off',
        placeholder: 'Pregunta o pide algo…',
        class: 'g-input',
        'aria-describedby': 'asistente-ayuda',
        'data-assistant-input': '',
    });
    const send = el('button', { type: 'submit', class: 'g-btn g-btn--primary g-assist__send', 'aria-label': 'Enviar', 'data-assistant-send': '' }, icon('send'));
    const form = el(
        'form',
        { class: 'g-assist__compose', method: 'post', action: urls.ask, 'data-assistant-form': '' },
        el('label', { for: 'asistente-pregunta', class: 'sr-only' }, 'Tu pregunta'),
        input,
        send,
    );
    const hint = el('p', { id: 'asistente-ayuda', class: 'g-assist__hint', 'data-assistant-hint': '' });
    const panel = el(
        'section',
        { id: 'asistente-panel', class: 'g-assist', role: 'dialog', 'aria-labelledby': 'asistente-titulo', hidden: '', 'data-assistant-panel': '' },
        el(
            'header',
            { class: 'g-assist__head' },
            icon('sparkle', { 'data-accent': '' }),
            el('div', { class: 'g-assist__heading' }, el('h2', { id: 'asistente-titulo', class: 'g-assist__title' }, 'Asistente'), subtitle),
            clear,
            close,
        ),
        log,
        el('div', { class: 'g-assist__foot' }, notice, form, hint),
        live,
    );
    doc.body.append(panel);
    fab.setAttribute('aria-controls', 'asistente-panel');
    fab.setAttribute('aria-haspopup', 'dialog');

    const closed = () => !state.available || state.full || state.budgetReached || state.remaining === 0;

    const showNotice = (message) => {
        notice.textContent = message;
        notice.hidden = !message;
    };

    function greeting() {
        const { first_name: name, company, role } = state.greeting ?? {};
        const node = el('div', { class: 'g-msg g-msg--bot', 'data-assistant-greeting': '' }, name ? `Hola, ${name}.` : 'Hola.');
        if (company) {
            node.append(' Estás en ', el('b', {}, company));
            if (role) node.append(' con perfil de ', el('b', {}, role.toLowerCase()));
            node.append('.');
        }
        node.append(' Pregúntame por lo que ya ves en el portal: tus apps, anuncios, fechas, cumpleaños, personas, tareas y el estado de TI.');
        return node;
    }

    function turn(role, html) {
        const body = el('div', { class: 'g-msg__text', 'data-assistant-html': '' });
        body.innerHTML = html;
        const me = role === 'user';
        return el(
            'div',
            { class: `g-msg ${me ? 'g-msg--me' : 'g-msg--bot'}`, 'data-assistant-turn': me ? 'user' : 'assistant' },
            el('span', { class: 'sr-only' }, me ? 'Tú: ' : 'Asistente: '),
            body,
        );
    }

    // review_url comes from the server; only a path of the portal is followed.
    function draftCard({ title, excerpt, review_url: url }) {
        const href = typeof url === 'string' && /^\/(?![/\\])/.test(url) ? url : `${fab.getAttribute('href')}#borrador`;
        return el(
            'article',
            { class: 'g-assist__draft', 'data-assistant-draft': '' },
            el('p', { class: 'g-label' }, 'Borrador de anuncio'),
            el('h3', { class: 'g-assist__draft-title' }, String(title ?? '')),
            el('p', {}, String(excerpt ?? '')),
            el('a', { href, class: 'g-btn g-btn--primary g-assist__review', 'data-assistant-review': '' }, 'Revisar y publicar'),
        );
    }

    function render() {
        const nodes = [];

        if (!state.loaded) {
            if (state.pending) nodes.push(el('p', { class: 'g-assist__status' }, TEXT.loading));
        } else if (!state.available) {
            nodes.push(el('p', { class: 'g-assist__status' }, TEXT.off));
        } else {
            nodes.push(greeting());
            if (state.restarted) nodes.push(el('p', { class: 'g-assist__status' }, TEXT.restarted));
            if (!state.turns.length && !state.asking && !closed()) {
                nodes.push(
                    el(
                        'div',
                        { class: 'g-assist__chips', role: 'group', 'aria-label': 'Puedes empezar con' },
                        ...SUGGESTIONS.map((text) => el('button', { type: 'button', class: 'g-chip', 'data-assistant-suggestion': '' }, text)),
                    ),
                );
            }
            nodes.push(...state.turns.map(({ role, html }) => turn(role, html)));
            if (state.asking) {
                const question = turn('user', '');
                question.querySelector('[data-assistant-html]').textContent = state.asking;
                nodes.push(question, el('p', { class: 'g-msg g-msg--bot g-assist__pending', 'data-assistant-pending': '' }, TEXT.thinking));
            }
            if (state.draft) nodes.push(draftCard(state.draft));
        }

        log.replaceChildren(...nodes);
        log.scrollTop = log.scrollHeight;
        log.setAttribute('aria-busy', state.pending ? 'true' : 'false');

        subtitle.textContent = [state.greeting?.role, state.greeting?.company].filter(Boolean).join(' · ');
        form.hidden = state.loaded && !state.available;
        clear.hidden = state.loaded && !state.available;
        clear.disabled = state.pending || !state.loaded;
        input.disabled = state.loaded && closed();
        send.disabled = state.pending || !state.loaded || closed();
        if (state.maxLength) input.setAttribute('maxlength', state.maxLength);

        hint.textContent = !state.loaded || !state.available
            ? ''
            : state.budgetReached
              ? TEXT.budget
              : state.full
                ? TEXT.full
                : state.remaining === 0
                  ? `Llegaste al límite de ${state.daily} preguntas de hoy. Mañana puedes seguir.`
                  : `${state.remaining === 1 ? 'Te queda 1 pregunta' : `Te quedan ${state.remaining} preguntas`} de ${state.daily} por hoy.`;
    }

    function apply(data) {
        state.turns = Array.isArray(data.turns) ? data.turns : [];
        state.draft = data.draft ?? null;
        state.remaining = Number(data.remaining ?? 0);
        state.full = data.full === true;
        state.budgetReached = data.budget_reached === true;
        if ('available' in data) {
            state.available = data.available === true;
            state.greeting = data.greeting;
            state.restarted = data.restarted === true;
            state.daily = Number(data.daily_questions ?? 0);
            state.maxLength = Number(data.max_length ?? 0);
        }
    }

    function errorFor(status, data) {
        if (status === 401 || status === 419) return TEXT.session;
        if (status === 404) return TEXT.off;
        if ([409, 422, 429, 503].includes(status) && typeof data?.error === 'string' && data.error) return data.error;
        return TEXT.failed;
    }

    async function request(method, url, body) {
        const token = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        let response;
        try {
            response = await win.fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token } : {}),
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            return { error: TEXT.network };
        }

        const data = await response.json().catch(() => null);
        if (response.ok && data) return { data };

        return { status: response.status, error: errorFor(response.status, data) };
    }

    async function load() {
        state.pending = true;
        render();
        const { data, error } = await request('GET', urls.conversation);
        state.pending = false;

        if (data) {
            apply(data);
            state.loaded = true;
        }
        showNotice(data ? '' : error);
        render();
        // A closed conversation disables the field that had the focus.
        if (!panel.hidden && input.disabled && (doc.activeElement === input || !panel.contains(doc.activeElement))) close.focus();
    }

    async function ask(text) {
        const question = String(text ?? '').trim();
        if (!question || state.pending || !state.loaded || closed()) return;

        showNotice('');
        state.pending = true;
        state.asking = question;
        render();

        const { data, status, error } = await request('POST', urls.ask, { pregunta: question });
        state.pending = false;
        state.asking = null;

        if (data) {
            apply(data);
            input.value = '';
            render();
            const answers = log.querySelectorAll('[data-assistant-turn="assistant"]');
            live.textContent = answers.length ? answers[answers.length - 1].querySelector('[data-assistant-html]').textContent : '';
            return;
        }

        input.value = question;
        showNotice(error);
        render();
        if (status === 409) {
            await load();
            state.restarted = false;
            showNotice(error);
            render();
        }
    }

    async function startOver() {
        if (state.pending || !state.loaded) return;

        state.pending = true;
        render();
        const { data, error } = await request('POST', urls.clear);
        state.pending = false;

        if (data) {
            apply(data);
            state.restarted = false;
            showNotice('');
            live.textContent = typeof data.status === 'string' ? data.status : '';
        } else {
            showNotice(error);
        }
        render();
        if (!input.disabled) input.focus();
    }

    function open() {
        panel.hidden = false;
        fab.hidden = true;
        fab.setAttribute('aria-expanded', 'true');
        (input.disabled || form.hidden ? close : input).focus();
        if (!state.loaded && !state.pending) {
            showNotice('');
            load();
        }
    }

    function hide() {
        panel.hidden = true;
        fab.hidden = false;
        fab.setAttribute('aria-expanded', 'false');
        fab.focus();
    }

    doc.addEventListener('click', (event) => {
        if (!panel.contains(event.target)) return;
        if (event.target.closest('[data-assistant-close]')) hide();
        else if (event.target.closest('[data-assistant-clear]')) startOver();
        else {
            const suggestion = event.target.closest('[data-assistant-suggestion]');
            // The chip goes away with the render: the focus stays in the panel.
            if (suggestion) {
                input.focus();
                ask(suggestion.textContent);
            }
        }
    });

    doc.addEventListener('submit', (event) => {
        if (event.target !== form) return;
        event.preventDefault();
        ask(input.value);
    });

    // An open header menu takes Escape first (resources/js/header.js).
    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || panel.hidden) return;
        if (!panel.contains(event.target) && doc.querySelector('details[data-menu][open]')) return;
        hide();
    });

    render();

    return { open };
}

// Loaded by header.js with a module <script>, which gets no exports: it opens
// the panel through this (the first click included). Vite drops the export.
if (typeof window !== 'undefined') window.openPortalAssistant = (fab) => openAssistant(document, window, fab);
