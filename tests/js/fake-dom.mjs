// A tiny stand-in for the DOM, enough for resources/js/theme.js,
// resources/js/header.js and resources/js/assistant-panel.js under Node's
// own runner. Selectors are matched for real, from the subset those scripts
// use (tag, [attr], [attr="v"], [attr*="v"], comma lists); anything else
// throws, so a typo in a script's selector fails its test instead of
// passing silently.
//
// Strings appended to an element become text nodes. Setting innerHTML keeps
// the markup as given (no parsing) and its text without the tags, so a test
// can tell text inserted as text (escaped in innerHTML) from markup.

const PART = /^([a-z][a-z0-9-]*)?((?:\[[^\]]+\])*)$/i;
const ATTRIBUTE = /\[([a-z][a-z0-9-]*)(?:(\*?=)"([^"]*)")?\]/gi;

function parse(selector) {
    return selector.split(',').map((raw) => {
        const part = raw.trim();
        const match = PART.exec(part);
        if (!match || part === '') throw new Error(`Unsupported selector: ${selector}`);

        const attributes = [...match[2].matchAll(ATTRIBUTE)].map(([, name, operator, value]) => ({ name, operator, value }));
        if (attributes.length !== (match[2].match(/\[/g) ?? []).length) {
            throw new Error(`Unsupported selector: ${selector}`);
        }

        return { tag: match[1]?.toLowerCase() ?? null, attributes };
    });
}

function matches(element, selector) {
    return parse(selector).some(({ tag, attributes }) =>
        (tag === null || element.tagName === tag) &&
        attributes.every(({ name, operator, value }) => {
            const actual = element.getAttribute(name);
            if (actual === null) return false;
            if (operator === '=') return actual === value;
            if (operator === '*=') return actual.includes(value);
            return true;
        }),
    );
}

const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const decode = (html) =>
    html
        .replace(/<[^>]*>/g, '')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/&amp;/g, '&');

export class FakeText {
    constructor(text) {
        this.textContent = String(text);
        this.parentElement = null;
    }

    get outerHTML() {
        return escape(this.textContent);
    }
}

export class FakeElement {
    constructor(doc, tagName, attributes = {}) {
        this.ownerDocument = doc;
        this.tagName = tagName.toLowerCase();
        this.attributes = new Map(Object.entries(attributes).map(([name, value]) => [name, String(value)]));
        this.children = [];
        this.parentElement = null;
        this.text = '';
        this.html = null;
    }

    get textContent() {
        return this.children.length ? this.children.map((child) => child.textContent).join('') : this.text;
    }

    set textContent(value) {
        this.replaceChildren();
        this.text = String(value);
    }

    get innerHTML() {
        if (this.children.length) return this.children.map((child) => child.outerHTML).join('');
        return this.html ?? escape(this.text);
    }

    set innerHTML(value) {
        this.replaceChildren();
        this.html = String(value);
        this.text = decode(this.html);
    }

    get outerHTML() {
        return `<${this.tagName}>${this.innerHTML}</${this.tagName}>`;
    }

    append(...children) {
        for (const raw of children) {
            const child = typeof raw === 'string' ? new FakeText(raw) : raw;
            child.parentElement?.children.splice(child.parentElement.children.indexOf(child), 1);
            child.parentElement = this;
            this.children.push(child);
        }
        return this;
    }

    replaceChildren(...children) {
        for (const child of this.children) child.parentElement = null;
        this.children = [];
        this.text = '';
        this.html = null;
        return this.append(...children);
    }

    remove() {
        this.parentElement?.children.splice(this.parentElement.children.indexOf(this), 1);
        this.parentElement = null;
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

    toggleAttribute(name, on) {
        if (on) this.setAttribute(name, '');
        else this.removeAttribute(name);
    }

    get open() {
        return this.hasAttribute('open');
    }

    set open(value) {
        this.toggleAttribute('open', value);
    }

    get hidden() {
        return this.hasAttribute('hidden');
    }

    set hidden(value) {
        this.toggleAttribute('hidden', value);
    }

    get dataset() {
        const data = {};
        for (const [name, value] of this.attributes) {
            if (name.startsWith('data-')) data[name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = value;
        }
        return data;
    }

    matches(selector) {
        return matches(this, selector);
    }

    closest(selector) {
        for (let node = this; node; node = node.parentElement) {
            if (node.matches(selector)) return node;
        }
        return null;
    }

    contains(other) {
        for (let node = other; node; node = node.parentElement) {
            if (node === this) return true;
        }
        return false;
    }

    *descendants() {
        for (const child of this.children) {
            if (!(child instanceof FakeElement)) continue;
            yield child;
            yield* child.descendants();
        }
    }

    querySelectorAll(selector) {
        parse(selector);
        return [...this.descendants()].filter((element) => element.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    focus() {
        this.ownerDocument.activeElement = this;
    }
}

export class FakeDocument {
    constructor() {
        this.documentElement = new FakeElement(this, 'html');
        this.activeElement = null;
        this.listeners = [];
    }

    element(tagName, attributes = {}, ...children) {
        return new FakeElement(this, tagName, attributes).append(...children);
    }

    createElement(tagName) {
        return new FakeElement(this, tagName);
    }

    get head() {
        return this.documentElement.querySelector('head');
    }

    get body() {
        return this.documentElement.querySelector('body');
    }

    querySelectorAll(selector) {
        return [this.documentElement, ...this.documentElement.querySelectorAll(selector)].filter((element) => element.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    addEventListener(type, listener, capture = false) {
        this.listeners.push({ type, listener, capture: capture === true || capture?.capture === true });
    }

    /** Capture listeners first, then bubbling ones, all on the document. */
    dispatch(type, target, properties = {}) {
        const event = { type, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...properties };
        for (const capture of [true, false]) {
            for (const entry of this.listeners) {
                if (entry.type === type && entry.capture === capture) entry.listener(event);
            }
        }
        return event;
    }
}
