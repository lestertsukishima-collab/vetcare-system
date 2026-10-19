import assert from 'node:assert/strict';
import test from 'node:test';
import { installClinicEvents } from '../../resources/js/clinic-events.js';

function browser({ refresh = async () => {}, online = true, workspace = true } = {}) {
    const events = () => {
        const handlers = new Map();
        return {
            addEventListener(type, handler) {
                if (!handlers.has(type)) handlers.set(type, []);
                handlers.get(type).push(handler);
            },
            fire(type, event = {}) {
                event.preventDefault ??= () => { event.defaultPrevented = true; };
                return Promise.all((handlers.get(type) ?? []).map(handler => handler(event)));
            },
        };
    };
    const makeButton = () => {
        const attributes = new Map();
        const classes = new Set();
        return {
            disabled: false,
            setAttribute: (name, value) => attributes.set(name, value),
            getAttribute: name => attributes.get(name),
            classList: { toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name) },
        };
    };
    let currentButton = makeButton();
    let currentWorkspace = workspace ? { isConnected: true, getAttribute: () => 'workspace-1' } : null;
    const messages = [];
    const calls = [];
    const document = { ...events(), querySelector: selector => ({
        '[data-clinic-workspace]': currentWorkspace,
        '[data-workspace-refresh]': currentButton,
    })[selector] ?? null };
    const window = {
        ...events(), navigator: { onLine: online },
        CustomEvent: class { constructor(type, { detail }) { this.type = type; this.detail = detail; } },
        dispatchEvent(event) { messages.push(event.detail.message); return true; },
        Livewire: { find: id => ({ $call: async method => {
            calls.push({ id, method });
            await refresh();
        } }) },
    };
    installClinicEvents({ window, document });
    return { window, document, messages, calls, get button() { return currentButton; },
        clickRefresh: () => document.fire('click', { target: { closest: () => currentButton } }),
        changePage() {
            currentWorkspace.isConnected = false;
            currentWorkspace = { isConnected: true, getAttribute: () => 'workspace-2' };
            currentButton = makeButton();
        },
    };
}

test('refresh waits for server completion before announcing success and releasing the button', async () => {
    let finish;
    const ui = browser({ refresh: () => new Promise(resolve => { finish = resolve; }) });
    const refreshing = ui.clickRefresh();
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.button.getAttribute('aria-busy'), 'true');
    assert.deepEqual(ui.messages, []);
    finish();
    await refreshing;
    assert.deepEqual(ui.calls, [{ id: 'workspace-1', method: 'refreshWorkspace' }]);
    assert.deepEqual(ui.messages, ['Workspace updated.']);
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.button.getAttribute('aria-busy'), 'false');
});

test('repeated clicks and reconnection cannot overlap an existing refresh', async () => {
    let finish;
    const ui = browser({ refresh: () => new Promise(resolve => { finish = resolve; }) });
    const refreshing = ui.clickRefresh();
    await ui.clickRefresh();
    await ui.window.fire('online');
    assert.equal(ui.calls.length, 1);
    finish();
    await refreshing;
});

test('offline clicks avoid requests and reconnection refreshes the workspace', async () => {
    const ui = browser({ online: false });
    await ui.clickRefresh();
    assert.deepEqual(ui.calls, []);
    assert.equal(ui.button.disabled, false);
    assert.match(ui.messages[0], /offline/);
    ui.window.navigator.onLine = true;
    await ui.window.fire('online');
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.messages.at(-1), 'Connection restored. Workspace updated.');
});

test('failed refreshes restore the button and can be retried', async () => {
    let failing = true;
    const ui = browser({ refresh: async () => { if (failing) throw new Error('Connection reset'); } });
    await ui.clickRefresh();
    assert.equal(ui.button.disabled, false);
    assert.match(ui.messages[0], /Could not refresh/);
    assert.equal(ui.messages.includes('Workspace updated.'), false);
    failing = false;
    await ui.clickRefresh();
    assert.equal(ui.messages.at(-1), 'Workspace updated.');
});

test('a stale completion cannot announce success or release a new page refresh', async () => {
    const finishes = [];
    const ui = browser({ refresh: () => new Promise(resolve => finishes.push(resolve)) });
    const first = ui.clickRefresh();
    await ui.document.fire('livewire:navigating');
    ui.changePage();
    const second = ui.clickRefresh();
    finishes[0]();
    await first;
    assert.deepEqual(ui.messages, []);
    assert.equal(ui.button.disabled, true);
    finishes[1]();
    await second;
    assert.deepEqual(ui.messages, ['Workspace updated.']);
    assert.equal(ui.button.disabled, false);
});

test('listeners install once and reconnection on other pages does nothing', async () => {
    const ui = browser();
    installClinicEvents({ window: ui.window, document: ui.document });
    await ui.clickRefresh();
    assert.equal(ui.calls.length, 1);
    const otherPage = browser({ workspace: false });
    await otherPage.window.fire('online');
    assert.deepEqual(otherPage.calls, []);
    assert.deepEqual(otherPage.messages, []);
});
