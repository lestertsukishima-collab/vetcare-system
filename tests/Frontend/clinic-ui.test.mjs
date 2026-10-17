import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = fs.readFileSync(new URL('../../public/clinic.js', import.meta.url), 'utf8');

// A small browser API fixture tests interaction state; it does not emulate layout.
function browser({ width = 375, overflow = false, originalAttributes = {} } = {}) {
    const listeners = () => {
        const handlers = new Map();
        return {
            addEventListener(type, handler) { if (!handlers.has(type)) handlers.set(type, []); handlers.get(type).push(handler); },
            fire(type, event = {}) {
                event.preventDefault ??= () => { event.defaultPrevented = true; };
                for (const handler of handlers.get(type) ?? []) handler(event);
                return event;
            },
        };
    };
    const document = { ...listeners(), readyState: 'complete' };
    const window = { ...listeners() };
    class Element {
        constructor(selectors = []) {
            this.selectors = selectors;
            this.attributes = new Map();
            this.isConnected = true;
            this.hidden = false;
            this.dataset = {};
            const classes = new Set();
            this.classList = {
                contains: name => classes.has(name),
                toggle: (name, enabled = !classes.has(name)) => enabled ? classes.add(name) : classes.delete(name),
                remove: name => classes.delete(name),
            };
        }
        setAttribute(name, value) { this.attributes.set(name, String(value)); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }
        removeAttribute(name) { this.attributes.delete(name); }
        matches(selector) { return this.selectors.includes(selector); }
        closest(selector) { return this.matches(selector) ? this : null; }
        focus() { document.activeElement = this; }
        getClientRects() { return this.hidden ? [] : [{}]; }
        remove() { this.isConnected = false; }
    }
    const toggle = new Element(['button,a,dialog', '[data-nav-toggle]']);
    const close = new Element(['button,a,dialog', '[data-nav-close]']);
    const active = new Element();
    const last = new Element();
    const main = new Element();
    const sidebar = new Element();
    sidebar.querySelector = () => close;
    sidebar.querySelectorAll = () => [close, active, last];
    sidebar.contains = element => [close, active, last].includes(element);
    const heading = { querySelector: () => ({ textContent: 'Patient appointments' }) };
    const hints = [];
    const regions = [];
    const makeRegion = (clientWidth = 280, scrollWidth = 700) => {
        const region = new Element();
        region.clientWidth = clientWidth;
        region.scrollWidth = scrollWidth;
        region.table = new Element();
        region.querySelector = () => region.table;
        region.closest = () => heading;
        region.insertAdjacentElement = (_, hint) => { hint.isConnected = true; hints.push(hint); };
        regions.push(region);
        return region;
    };
    const initialRegion = overflow ? makeRegion() : null;
    if (initialRegion) for (const [name, value] of Object.entries(originalAttributes)) initialRegion.setAttribute(name, value);
    document.body = new Element();
    document.documentElement = new Element();
    document.activeElement = main;
    document.createElement = () => { const element = new Element(); element.isConnected = false; return element; };
    document.querySelector = selector => ({ '#clinic-navigation': sidebar, '#main-content': main, '[data-nav-toggle]': toggle, '.main-nav a.active': active })[selector] ?? null;
    document.querySelectorAll = selector => selector === '.scroll' ? regions.filter(region => region.isConnected) : [];
    const frames = new Map();
    let frame = 0;
    const observed = new Set();
    class ResizeObserver {
        observe(element) { observed.add(element); }
        unobserve(element) { observed.delete(element); }
    }
    const hooks = new Map();
    window.Livewire = { hook: (name, handler) => hooks.set(name, handler) };
    vm.runInNewContext(script, {
        window, document, ResizeObserver,
        location: { hash: '', pathname: '/clinic/pets' },
        matchMedia: query => ({ matches: query === '(max-width: 1024px)' && width <= 1024 }),
        localStorage: { getItem: () => null, setItem() {} },
        requestAnimationFrame: callback => { frames.set(++frame, callback); return frame; },
        cancelAnimationFrame: id => frames.delete(id),
        setTimeout: () => 1, clearTimeout() {},
    });
    const flush = () => {
        let batches = 0;
        while (frames.size) {
            assert.ok(++batches < 10, 'UI updates should settle');
            const callbacks = [...frames.values()];
            frames.clear();
            callbacks.forEach(callback => callback());
        }
    };
    flush();
    return { document, window, toggle, close, active, last, main, sidebar, hints, regions, makeRegion, initialRegion, observed, hooks, flush,
        click: target => { document.fire('click', { target }); flush(); },
        key: (key, shiftKey = false) => { const event = document.fire('keydown', { key, shiftKey }); flush(); return event; },
        resize: newWidth => { width = newWidth; window.fire('resize'); flush(); },
        morph: () => { hooks.get('morphed')(); flush(); },
    };
}

test('mobile navigation contains focus and Escape restores its trigger', () => {
    const ui = browser();
    ui.click(ui.toggle);
    assert.equal(ui.main.inert, true);
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(ui.sidebar.getAttribute('aria-modal'), 'true');
    assert.equal(ui.document.activeElement, ui.active);
    ui.last.focus();
    assert.equal(ui.key('Tab').defaultPrevented, true);
    assert.equal(ui.document.activeElement, ui.close);
    ui.key('Tab', true);
    assert.equal(ui.document.activeElement, ui.last);
    ui.key('Escape');
    assert.equal(ui.main.inert, false);
    assert.equal(ui.document.body.classList.contains('nav-open'), false);
    assert.equal(ui.sidebar.getAttribute('role'), null);
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(ui.document.activeElement, ui.toggle);
});

test('resizing to desktop restores content and Livewire navigation clears drawer state', () => {
    const ui = browser();
    ui.click(ui.toggle);
    ui.resize(1280);
    assert.equal(ui.main.inert, false);
    assert.equal(ui.document.body.classList.contains('nav-open'), false);
    assert.equal(ui.document.activeElement, ui.active);
    ui.click(ui.toggle);
    assert.equal(ui.main.inert, false);
    ui.resize(375);
    ui.click(ui.toggle);
    ui.document.fire('livewire:navigated');
    ui.flush();
    assert.equal(ui.main.inert, false);
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'false');
});

test('overflowing tables have keyboard access and hints only while needed', () => {
    const ui = browser({ overflow: true, originalAttributes: { 'aria-label': 'Appointment list', 'aria-describedby': 'existing-help' } });
    const region = ui.initialRegion;
    assert.equal(region.getAttribute('tabindex'), '0');
    assert.equal(region.getAttribute('role'), 'region');
    assert.equal(region.getAttribute('aria-label'), 'Appointment list');
    assert.equal(region.getAttribute('aria-describedby'), `existing-help ${ui.hints[0].id}`);
    assert.equal(ui.hints[0].hidden, false);
    region.clientWidth = region.scrollWidth;
    ui.resize(1440);
    assert.equal(ui.hints[0].hidden, true);
    assert.equal(region.getAttribute('tabindex'), null);
    assert.equal(region.getAttribute('role'), null);
    assert.equal(region.getAttribute('aria-label'), 'Appointment list');
    assert.equal(region.getAttribute('aria-describedby'), 'existing-help');
});

test('Livewire table replacements are observed and removed regions release observers', () => {
    const ui = browser();
    const region = ui.makeRegion();
    ui.morph();
    assert.equal(region.getAttribute('tabindex'), '0');
    const oldTable = region.table;
    region.table = {};
    ui.morph();
    assert.equal(ui.observed.has(oldTable), false);
    assert.equal(ui.observed.has(region.table), true);
    region.isConnected = false;
    ui.morph();
    assert.equal(ui.observed.size, 0);
    assert.equal(ui.hints[0].isConnected, false);
});
