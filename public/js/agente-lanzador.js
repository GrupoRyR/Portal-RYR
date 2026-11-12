/*!
 * Agente RYR floating panel launcher (docs/CONTRATO_AGENTE.md §5.2).
 * LANZADOR_VERSION = '2'
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
 *   iframe window, and every field is checked: {type: "panel:close"} and
 *   {type: "panel:resize", mode: "wide" | "normal"}. Nothing else.
 * - The panel never receives or sends a bearer, a PIN or a revealed value
 *   through postMessage; this script never sees them.
 * - Nothing is stored and the script makes no network calls of its own.
 */
(function () {
    'use strict';

    var LANZADOR_VERSION = '2';
    var ORIGIN_ATTRIBUTE = 'data-agente-origen';
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
     * {action: "resize", mode} or null. Only the documented shape passes:
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

    /** Replaces the link with the floating button and the (lazy) panel. */
    function enhance(link, origin) {
        if (link.getAttribute('data-agente-listo') === '1' || typeof link.attachShadow !== 'function') {
            return;
        }
        var token = tokenFromLink(link.href, origin);
        if (token === null) {
            return; // not ours to enhance: it stays a plain link
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

        shadow.appendChild(fab);
        shadow.appendChild(panel);

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

        function show() {
            if (frame === null) {
                frame = document.createElement('iframe');
                frame.className = 'frame';
                frame.title = 'Asistente';
                frame.setAttribute('allow', 'microphone');
                frame.setAttribute('referrerpolicy', 'no-referrer');
                frame.src = frameSource(origin, token);
                token = null; // single use: nothing keeps it now
                panel.appendChild(frame);
            }
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
            panel.hidden = true;
            fab.hidden = false;
            if (scrollBefore !== null) {
                document.body.style.overflow = scrollBefore;
                scrollBefore = null;
            }
            fab.focus();
        }

        fab.addEventListener('click', show);

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
        var links = document.querySelectorAll('a[data-agente-panel]');
        for (var i = 0; i < links.length; i += 1) {
            enhance(links[i], origin);
        }
    }

    // Under Node's test runner the pure helpers are handed out and nothing runs.
    if (typeof __AGENTE_PANEL_TEST__ !== 'undefined') {
        __AGENTE_PANEL_TEST__.api = { agentOrigin: agentOrigin, originFromAttribute: originFromAttribute, chooseOrigin: chooseOrigin, tokenFromLink: tokenFromLink, frameSource: frameSource, parseMessage: parseMessage, isFromPanel: isFromPanel, version: LANZADOR_VERSION };

        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
