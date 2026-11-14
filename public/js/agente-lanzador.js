/*!
 * Agente RYR floating panel launcher (docs/CONTRATO_AGENTE.md §5.2).
 * LANZADOR_VERSION = '3'
 *
 * A plain classic script, no imports. The agent publishes it at the stable
 * address /panel/lanzador.js as the download source; each app serves its OWN
 * copy from its own origin (so its Content-Security-Policy keeps
 * script-src 'self') and tells the copy where the agent is:
 *
 *   <script src="/js/agente-lanzador.js" data-agente-origen="https://<agent>" defer></script>
 *   <a href="https://<agent>/panel/abrir#t=<jwt>" data-agente-panel target="_blank" rel="noopener">Preguntar al agente</a>
 *
 * data-agente-origen must be a bare https origin (scheme, host, optional
 * port; no path, query, fragment or credentials), written the way the browser
 * writes it. When it is present but not valid the launcher does nothing: it
 * never falls back to the script's own address. Without the attribute the
 * agent origin is the origin the script was loaded from, so a copy loaded
 * straight from the agent keeps working.
 *
 * data-agente-token-url (optional) is a same-origin path of the app, for
 * example /agente/token. With it the app does not put a token in the page at
 * all: each time the panel needs a session the launcher POSTs to that path
 * (the one network call it makes: same origin, session cookie, CSRF header
 * from <meta name="csrf-token">, no redirects, about ten seconds at most),
 * expects {"token": "<jwt>"} and opens the iframe with it. A failed request
 * shows a short message and never falls back to a token on the link. When it
 * is present but not a plain same-origin path the launcher does nothing.
 * Without it the token comes from the link, as in version 2.
 *
 * It turns every a[data-agente-panel] into a floating button. A click opens
 * the agent's /panel/abrir page in an iframe and hands it the app token in
 * the URL fragment (never the query, so it stays out of access logs). The
 * token is read from the link, removed from it, used once and forgotten. The
 * link carries it in the fragment (#t=), which never reaches a server; the
 * older ?t= form is still read but is deprecated.
 *
 * Rules this file keeps:
 * - Everything lives in a closed Shadow DOM: the host page's CSS cannot
 *   reach the button or the panel, and nothing is added to the global scope.
 *   On the page it only adds the button's host element (it carries
 *   data-agente-lanzador, the version) and marks the link it enhanced
 *   (data-agente-listo, href, target, rel, hidden); on phones it also locks
 *   the body scroll while the panel is open.
 * - Styles are applied through a constructed stylesheet (CSSOM), which a
 *   strict host Content-Security-Policy does not block.
 * - The token is only ever sent to /panel/abrir of the agent origin.
 * - Messages are accepted only from that origin AND from the panel's own
 *   iframe window, and every field is checked: {type: "panel:close"},
 *   {type: "panel:resize", mode: "wide" | "normal"} and {type: "panel:expired"}.
 *   Nothing else. With a token URL, panel:expired makes the launcher fetch a
 *   new token and replace the iframe, once per click on the button; a second
 *   expiry before the next click closes the panel with the message.
 * - The panel never receives or sends a bearer, a PIN or a revealed value
 *   through postMessage; this script never sees them.
 * - Nothing is stored. The token is never logged, kept after use or sent
 *   anywhere but /panel/abrir of the agent origin (as the iframe fragment).
 */
(function () {
    'use strict';

    var LANZADOR_VERSION = '3';
    var ORIGIN_ATTRIBUTE = 'data-agente-origen';
    var TOKEN_URL_ATTRIBUTE = 'data-agente-token-url';
    var TOKEN_TIMEOUT = 10000;
    var LONG_SESSION = 60000; // a session that lived this long (ms) earns a new renewal when it ends
    var JSON_TYPE = /^application\/json\s*(;|$)/i;
    var FAILURE_TEXT = 'No se pudo abrir el asistente. Intenta de nuevo.';
    var PANEL_PATH = '/panel/abrir';
    var TOKEN = /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/;
    var MAX_TOKEN = 4096;
    var MAX_LABEL = 60;
    var BRANDS = { neutral: true, segurex: true, derivados: true };

    /** The origin of a script address, or null when it has no usable one. */
    function agentOrigin(address) {
        try {
            var origin = new URL(address).origin;

            return origin === 'null' ? null : origin;
        } catch (error) {
            return null;
        }
    }

    /**
     * The agent origin an app declared in data-agente-origen: a bare https
     * origin or null. The value must equal what the browser itself would print
     * as the origin of that address, which rules out in one comparison a path
     * (even "/"), a query, a fragment, credentials, a default port written
     * out, backslash tricks, stray whitespace and any other scheme.
     */
    function originFromAttribute(value) {
        if (typeof value !== 'string') {
            return null;
        }
        try {
            var url = new URL(value);

            return url.protocol === 'https:' && url.origin === value ? value : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * The agent origin for this copy. The attribute decides when the script tag
     * has it (null for the attribute means absent; any present value must be
     * valid, otherwise there is no origin and no fallback). Without it, the
     * origin of the script address.
     */
    function chooseOrigin(attribute, address) {
        if (attribute === null || attribute === undefined) {
            return address ? agentOrigin(address) : null;
        }

        return originFromAttribute(attribute);
    }

    /**
     * The absolute address of the app's token endpoint from data-agente-token-url,
     * or null. It must be a path of the app's own origin, written as the browser
     * writes it: the value must equal the resolved pathname plus query, which
     * rules out another origin (also "//host" and "/\\host"), an absolute
     * address, credentials, a fragment, dot segments and stray whitespace.
     */
    function tokenUrlFromAttribute(value, pageOrigin) {
        if (typeof value !== 'string' || typeof pageOrigin !== 'string' || pageOrigin === 'null') {
            return null;
        }
        try {
            var url = new URL(value, pageOrigin);
            var plain = url.origin === pageOrigin && (url.protocol === 'https:' || url.protocol === 'http:') && url.username === '' && url.password === '' && url.hash === '';

            return value.charAt(0) === '/' && plain && url.pathname + url.search === value ? url.href : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * Asks the app for a fresh panel token: one same-origin POST, nothing else.
     * Resolves with a well-formed token or rejects with a generic error that
     * never carries the reply. env holds the browser functions (injected so a
     * test can drive them): fetch, AbortController, setTimeout, clearTimeout.
     */
    function requestToken(tokenUrl, csrf, env) {
        return new Promise(function (resolve, reject) {
            var timer = null;

            function done() {
                if (timer !== null) {
                    env.clearTimeout(timer);
                }
            }

            function fail() {
                done();
                reject(new Error('token request failed'));
            }

            try {
                var headers = { Accept: 'application/json' };
                if (typeof csrf === 'string' && csrf !== '') {
                    headers['X-CSRF-TOKEN'] = csrf;
                }
                var controller = new env.AbortController();
                timer = env.setTimeout(function () {
                    controller.abort();
                }, TOKEN_TIMEOUT);

                env.fetch(tokenUrl, { method: 'POST', credentials: 'same-origin', headers: headers, redirect: 'error', signal: controller.signal })
                    .then(function (response) {
                        var type = response.headers.get('content-type') || '';
                        if (response.status !== 200 || response.redirected || !JSON_TYPE.test(type)) {
                            throw new Error('refused');
                        }

                        return response.json();
                    })
                    .then(function (body) {
                        var token = Object.prototype.toString.call(body) === '[object Object]' ? body.token : null;
                        if (typeof token !== 'string' || token.length > MAX_TOKEN || !TOKEN.test(token)) {
                            throw new Error('refused');
                        }
                        done();
                        resolve(token);
                    })
                    .catch(fail);
            } catch (error) {
                fail();
            }
        });
    }

    /** The CSRF token of the host page, or null when it has no meta for it. */
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var value = meta ? meta.getAttribute('content') : null;

        return typeof value === 'string' && value !== '' ? value : null;
    }

    /** True when the link points at the panel page of the agent origin, whatever it carries. */
    function isPanelLink(href, origin) {
        try {
            var url = new URL(href);

            return url.origin === origin && url.pathname === PANEL_PATH;
        } catch (error) {
            return false;
        }
    }

    /** The value of t in a "a=b&t=c" string, or null. */
    function tokenParam(parameters) {
        var token = new URLSearchParams(parameters).get('t');

        return token !== null && token.length <= MAX_TOKEN && TOKEN.test(token) ? token : null;
    }

    /**
     * The app token from the link, only when the link points at the panel page
     * of the agent origin. It is read from the fragment (#t=) first; the older
     * ?t= form is still accepted but deprecated. A fragment that carries a t is
     * the only source: a bad one never falls back to the query. Anything else
     * (another host, another path, a malformed token) gives null, so a token is
     * never sent anywhere else.
     */
    function tokenFromLink(href, origin) {
        var url;
        try {
            url = new URL(href);
        } catch (error) {
            return null;
        }
        if (url.origin !== origin || url.pathname !== PANEL_PATH) {
            return null;
        }
        var fragment = url.hash.charAt(0) === '#' ? url.hash.slice(1) : url.hash;
        if (new URLSearchParams(fragment).has('t')) {
            return tokenParam(fragment);
        }

        return tokenParam(url.search); // deprecated: ?t=
    }

    /** The iframe address: the token goes in the fragment, never in the query. */
    function frameSource(origin, token) {
        return origin + PANEL_PATH + '#t=' + token;
    }

    /**
     * Validates a message from the panel. Returns {action: "close"},
     * {action: "expired"}, {action: "resize", mode} or null. Only the documented shape passes:
     * a plain object with exactly the expected keys.
     */
    function parseMessage(data) {
        if (Object.prototype.toString.call(data) !== '[object Object]') {
            return null;
        }
        var keys = Object.keys(data);

        if (data.type === 'panel:close') {
            return keys.length === 1 ? { action: 'close' } : null;
        }
        if (data.type === 'panel:expired') {
            return keys.length === 1 ? { action: 'expired' } : null;
        }
        if (data.type === 'panel:resize') {
            return keys.length === 2 && (data.mode === 'wide' || data.mode === 'normal') ? { action: 'resize', mode: data.mode } : null;
        }

        return null;
    }

    /** True only for a message that came from the agent origin and from our iframe. */
    function isFromPanel(event, origin, frameWindow) {
        return Boolean(event) && event.origin === origin && Boolean(frameWindow) && event.source === frameWindow;
    }

    var CSS = [
        ':host{all:initial}',
        '*,*::before,*::after{box-sizing:border-box}',
        '[hidden]{display:none!important}',
        '.fab{--bg:#17191C;--fg:#FFFFFF;position:fixed;right:max(20px,env(safe-area-inset-right));bottom:calc(20px + env(safe-area-inset-bottom));z-index:2147483000;display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:12px 20px;border:1px solid rgb(255 255 255 / .35);border-radius:999px;font:700 15px/1.2 Helvetica,Arial,sans-serif;color:var(--fg);background:var(--bg);box-shadow:inset 0 1px 0 rgb(255 255 255 / .28),0 10px 28px rgb(0 0 0 / .28);-webkit-backdrop-filter:blur(14px) saturate(1.4);backdrop-filter:blur(14px) saturate(1.4);cursor:pointer}',
        '.fab[data-marca="segurex"]{--bg:#C6531C;--fg:#FFFFFF}',
        '.fab[data-marca="derivados"]{--bg:#0B5FA5;--fg:#FFFFFF}',
        '@media (prefers-color-scheme:dark){.fab[data-marca="neutral"]{--bg:#F2F2EF;--fg:#17191C;border-color:rgb(0 0 0 / .25)}}',
        '.fab:focus-visible{outline:2px solid #FFFFFF;outline-offset:0;box-shadow:0 0 0 5px #17191C}',
        '.icon{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}',
        '.panel{position:fixed;right:max(16px,env(safe-area-inset-right));bottom:max(16px,env(safe-area-inset-bottom));z-index:2147483000;width:min(400px,calc(100vw - 32px));height:min(680px,calc(100vh - 32px));height:min(680px,calc(100dvh - 32px));overflow:hidden;border-radius:24px;background:#17191C;box-shadow:0 18px 56px rgb(0 0 0 / .38)}',
        '.panel[data-modo="wide"]{width:min(1000px,calc(100vw - 32px))}',
        '.frame{display:block;width:100%;height:100%;border:0}',
        '.aviso{position:fixed;right:max(20px,env(safe-area-inset-right));bottom:calc(88px + env(safe-area-inset-bottom));z-index:2147483000;max-width:min(320px,calc(100vw - 40px));margin:0;padding:10px 14px;border:1px solid rgb(255 255 255 / .35);border-radius:14px;font:400 14px/1.4 Helvetica,Arial,sans-serif;color:#FFFFFF;background:#17191C;box-shadow:0 10px 28px rgb(0 0 0 / .28)}',
        '.aviso:empty{width:1px;height:1px;padding:0;border:0;overflow:hidden;clip-path:inset(50%);box-shadow:none;opacity:0}',
        '.fab[aria-busy="true"]{cursor:progress;opacity:.85}',
        '@media (max-width:639px){.fab{right:max(16px,env(safe-area-inset-right));bottom:calc(16px + env(safe-area-inset-bottom));width:52px;height:52px;padding:0;justify-content:center}.label{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}.panel,.panel[data-modo="wide"]{inset:0;width:auto;height:auto;border-radius:0}}',
        '@media (prefers-reduced-motion:no-preference){.panel:not([hidden]){animation:enter .2s ease-out}@keyframes enter{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}}',
    ].join('\n');

    /** The shadow root's stylesheet. Returns false when it could not be applied. */
    function applyStyles(shadow) {
        try {
            var sheet = new CSSStyleSheet();
            sheet.replaceSync(CSS);
            shadow.adoptedStyleSheets = [sheet];

            return true;
        } catch (error) {
            return false;
        }
    }

    function buildIcon() {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'icon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var path = document.createElementNS(ns, 'path');
        path.setAttribute('d', 'M4 5h16v11H9l-5 4z');
        svg.appendChild(path);

        return svg;
    }

    /**
     * Replaces the link with the floating button and the (lazy) panel. config is
     * {tokenUrl, env}: with a token URL the panel session starts from a token
     * fetched on click; without one the token comes from the link (version 2).
     */
    function enhance(link, origin, config) {
        if (link.getAttribute('data-agente-listo') === '1' || typeof link.attachShadow !== 'function') {
            return;
        }
        var fetching = config.tokenUrl !== null;
        var token = null;
        if (fetching) {
            if (!isPanelLink(link.href, origin)) {
                return; // not ours to enhance: it stays a plain link
            }
        } else {
            token = tokenFromLink(link.href, origin);
            if (token === null) {
                return; // not ours to enhance: it stays a plain link
            }
        }

        var host = document.createElement('div');
        host.setAttribute('data-agente-lanzador', LANZADOR_VERSION);
        var shadow = host.attachShadow({ mode: 'closed' });
        if (!applyStyles(shadow)) {
            return; // no styling means no floating UI: the link still opens the chat
        }

        var label = (link.textContent || '').replace(/\s+/g, ' ').trim().slice(0, MAX_LABEL) || 'Preguntar al agente';
        var marca = link.getAttribute('data-agente-marca');

        var fab = document.createElement('button');
        fab.type = 'button';
        fab.className = 'fab';
        fab.setAttribute('data-marca', Object.prototype.hasOwnProperty.call(BRANDS, marca) ? marca : 'neutral');
        var text = document.createElement('span');
        text.className = 'label';
        text.textContent = label;
        fab.appendChild(buildIcon());
        fab.appendChild(text);

        var panel = document.createElement('section');
        panel.className = 'panel';
        panel.setAttribute('aria-label', 'Asistente');
        panel.hidden = true;

        // A live region that is always rendered (visually clipped while empty, never display:none):
        // only its text changes, so a screen reader announces it.
        var notice = document.createElement('p');
        notice.className = 'aviso';
        notice.setAttribute('role', 'status');

        shadow.appendChild(fab);
        shadow.appendChild(panel);
        shadow.appendChild(notice);

        // The link keeps working for a modified click (new tab): it leads to the
        // full chat, because the one-use token is gone from it.
        link.href = origin + '/';
        link.target = '_blank';
        link.rel = 'noopener';
        link.setAttribute('data-agente-listo', '1');
        link.style.display = 'none';
        document.body.appendChild(host);

        var frame = null;
        var scrollBefore = null;
        var phone = window.matchMedia('(max-width: 639px)');
        var alive = false; // the iframe holds a session that has not been reported as ended
        var requesting = false; // a token request is in flight
        var refreshes = 0; // new sessions the launcher may still start for this click
        var wantOpen = false; // the person wants the panel open (a close while waiting cancels it)
        var openedAt = 0; // when the current iframe was created (ms)

        function createFrame(fragmentToken) {
            var element = document.createElement('iframe');
            element.className = 'frame';
            element.title = 'Asistente';
            element.setAttribute('allow', 'microphone');
            element.setAttribute('referrerpolicy', 'no-referrer');
            element.src = frameSource(origin, fragmentToken);
            panel.appendChild(element);

            return element;
        }

        /** An iframe is replaced, never re-pointed: a change of fragment alone would not reload it. */
        function dropFrame() {
            if (frame !== null) {
                frame.remove();
                frame = null;
            }
            alive = false;
            panel.removeAttribute('data-modo'); // a new panel page starts in the normal width
        }

        function reveal() {
            panel.hidden = false;
            fab.hidden = true;
            if (phone.matches && scrollBefore === null) {
                // Full screen: the page behind must not scroll under the panel.
                scrollBefore = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
            }
            frame.focus();
        }

        function hide() {
            wantOpen = false;
            panel.hidden = true;
            fab.hidden = false;
            if (scrollBefore !== null) {
                document.body.style.overflow = scrollBefore;
                scrollBefore = null;
            }
            fab.focus();
        }

        /** The session could not be started: no iframe, the button still works, the person is told. */
        function giveUp() {
            dropFrame();
            hide();
            notice.textContent = FAILURE_TEXT;
        }

        /** Fetches a token and opens a new iframe with it. */
        function openFetched() {
            if (requesting) {
                return;
            }
            requesting = true;
            fab.setAttribute('aria-busy', 'true');
            requestToken(config.tokenUrl, csrfToken(), config.env).then(
                function (fresh) {
                    requesting = false;
                    fab.removeAttribute('aria-busy');
                    dropFrame(); // the old, dead iframe stayed until now so the panel was never blank
                    frame = createFrame(fresh); // single use: the launcher keeps no copy of the token
                    alive = true;
                    openedAt = Date.now();
                    if (wantOpen) {
                        reveal();
                    }
                },
                function () {
                    requesting = false;
                    fab.removeAttribute('aria-busy');
                    giveUp();
                },
            );
        }

        function show() {
            if (frame === null) {
                frame = createFrame(token);
                token = null; // single use: nothing keeps it now
            }
            reveal();
        }

        fab.addEventListener('click', function () {
            if (!fetching) {
                show();

                return;
            }
            if (requesting) {
                wantOpen = true; // the panel opens as soon as the token arrives
                return;
            }
            notice.textContent = '';
            wantOpen = true;
            refreshes = 1; // a click is the user action that allows one new session
            if (alive && frame !== null) {
                reveal(); // the session is still alive: no new token
            } else {
                openFetched();
            }
        });

        window.addEventListener('message', function (event) {
            if (frame === null || !isFromPanel(event, origin, frame.contentWindow)) {
                return;
            }
            var message = parseMessage(event.data);
            if (message === null) {
                return;
            }
            if (message.action === 'close') {
                hide();
            } else if (message.action === 'expired') {
                if (!fetching) {
                    return; // version 2 behavior: the panel says "reload the page"
                }
                if (requesting) {
                    return; // a new session is already on its way
                }
                if (panel.hidden) {
                    dropFrame(); // nobody is looking: the next click starts a new session
                    return;
                }
                if (Date.now() - openedAt >= LONG_SESSION) {
                    refreshes = 1; // the person used this session for a while: not a loop
                }
                if (refreshes > 0) {
                    refreshes -= 1;
                    alive = false;
                    openFetched(); // the dead iframe stays (with its own close button) until the new one is ready
                } else {
                    giveUp();
                }
            } else {
                panel.setAttribute('data-modo', message.mode); // also while hidden: the panel keeps its own idea of the mode
            }
        });
    }

    // document.currentScript is only set while the script runs: read it now.
    var current = document.currentScript;

    function start() {
        if (!current) {
            return; // loaded in a way that hides its own tag: do nothing
        }
        var origin = chooseOrigin(current.getAttribute(ORIGIN_ATTRIBUTE), current.src);
        if (origin === null) {
            return; // no usable agent origin: do nothing, never guess one
        }
        var config = { tokenUrl: null, env: null };
        var tokenAttribute = current.getAttribute(TOKEN_URL_ATTRIBUTE);
        if (tokenAttribute !== null && tokenAttribute !== undefined) {
            config.tokenUrl = tokenUrlFromAttribute(tokenAttribute, window.location.origin);
            if (config.tokenUrl === null) {
                return; // present but not a plain same-origin path: do nothing, never fall back to the link token
            }
            config.env = {
                fetch: typeof window.fetch === 'function' ? window.fetch.bind(window) : undefined,
                AbortController: window.AbortController,
                setTimeout: window.setTimeout.bind(window),
                clearTimeout: window.clearTimeout.bind(window),
            };
        }
        var links = document.querySelectorAll('a[data-agente-panel]');
        for (var i = 0; i < links.length; i += 1) {
            enhance(links[i], origin, config);
        }
    }

    // Under Node's test runner the pure helpers are handed out and nothing runs.
    if (typeof __AGENTE_PANEL_TEST__ !== 'undefined') {
        __AGENTE_PANEL_TEST__.api = { tokenUrlFromAttribute: tokenUrlFromAttribute, requestToken: requestToken, agentOrigin: agentOrigin, originFromAttribute: originFromAttribute, chooseOrigin: chooseOrigin, tokenFromLink: tokenFromLink, frameSource: frameSource, parseMessage: parseMessage, isFromPanel: isFromPanel, version: LANZADOR_VERSION };

        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
