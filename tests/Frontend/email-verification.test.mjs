import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { setImmediate } from 'node:timers/promises';

const script = fs.readFileSync(new URL('../../public/email-verification.js', import.meta.url), 'utf8');
const settle = () => setImmediate();

function channelNetwork() {
    const channels = new Set();
    return class BroadcastChannel {
        constructor(name) { this.name = name; channels.add(this); }
        postMessage(data) {
            for (const peer of channels) {
                if (peer === this || peer.name !== this.name) continue;
                queueMicrotask(() => {
                    if (channels.has(peer)) peer.onmessage?.({ data: structuredClone(data) });
                });
            }
        }
        close() { channels.delete(this); }
    };
}

function browser({ waiting = false, complete = false, userId = '7', server = { userId, verified: false }, Channel = channelNetwork(), hidden = false } = {}) {
    const events = () => {
        const listeners = new Map();
        return {
            addEventListener(type, handler) {
                if (!listeners.has(type)) listeners.set(type, new Set());
                listeners.get(type).add(handler);
            },
            removeEventListener(type, handler) { listeners.get(type)?.delete(handler); },
            fire(type, event = {}) { for (const handler of [...(listeners.get(type) ?? [])]) handler(event); },
        };
    };
    const timers = new Map();
    let timerId = 0;
    const redirects = [];
    const requests = [];
    let closed = false;
    const document = { ...events(), readyState: 'complete', hidden };
    document.querySelector = selector => {
        if (selector === '[data-email-verification]' && waiting) return {
            dataset: { userId, statusUrl: '/email/verification-status', redirectUrl: '/dashboard' },
        };
        if (selector === 'meta[name="email-verification-complete"]' && complete) return { content: userId };
        return null;
    };
    const window = {
        ...events(),
        location: { replace: url => redirects.push(url) },
        close() { closed = true; window.fire('pagehide'); },
    };
    vm.runInNewContext(script, {
        window, document, BroadcastChannel: Channel, AbortController,
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (server.fail) throw new Error('Offline');
            return {
                ok: !server.status || server.status === 200,
                status: server.status ?? 200,
                json: async () => ({ user_id: server.userId, verified: server.verified }),
            };
        },
        setTimeout(callback, delay) { timers.set(++timerId, { callback, delay }); return timerId; },
        clearTimeout(id) { timers.delete(id); },
    });
    return {
        document, window, redirects, requests, timers, get closed() { return closed; },
        tick(delay = 5000) {
            const timer = [...timers].find(([, value]) => value.delay === delay);
            if (!timer) return false;
            timers.delete(timer[0]);
            timer[1].callback();
            return true;
        },
    };
}

test('a successful email link continues in the original hidden tab and closes the extra tab', async () => {
    const Channel = channelNetwork();
    const server = { userId: '7', verified: false };
    const original = browser({ waiting: true, server, Channel });
    await settle();
    assert.deepEqual(original.redirects, []);
    original.document.hidden = true;
    server.verified = true;
    const extra = browser({ complete: true, Channel });
    await settle();
    assert.deepEqual(original.redirects, ['/dashboard']);
    assert.equal(extra.closed, true);
    assert.equal(original.timers.size, 0);
    assert.equal(extra.timers.size, 0);
    assert.equal(original.requests.at(-1).options.cache, 'no-store');
    assert.equal(original.requests.at(-1).options.credentials, 'same-origin');
});

test('a tab message cannot bypass the server verification check', async () => {
    const Channel = channelNetwork();
    const original = browser({ waiting: true, Channel });
    await settle();
    const extra = browser({ complete: true, Channel });
    await settle();
    assert.deepEqual(original.redirects, []);
    assert.equal(extra.closed, false);
});

test('a standalone verification link keeps its dashboard open', async () => {
    const extra = browser({ complete: true });
    await settle();
    assert.equal(extra.closed, false);
    extra.tick(3000);
    assert.equal(extra.closed, false);
    assert.equal(extra.timers.size, 0);
});

test('verification also continues by polling when tab messaging is unavailable', async () => {
    const server = { userId: '7', verified: false };
    const fallback = browser({ waiting: true, server, Channel: class { constructor() { throw new Error('Unavailable'); } }, hidden: true });
    await settle();
    server.verified = true;
    fallback.tick();
    await settle();
    assert.deepEqual(fallback.redirects, ['/dashboard']);
});

test('a background tab still continues when verification happens without a tab message', async () => {
    const server = { userId: '7', verified: false };
    const original = browser({ waiting: true, server, hidden: true });
    await settle();
    server.verified = true;
    original.tick();
    await settle();
    assert.deepEqual(original.redirects, ['/dashboard']);
});

test('temporary network failures recover and returning to the tab checks immediately', async () => {
    const server = { userId: '7', verified: false, fail: true };
    const original = browser({ waiting: true, server });
    await settle();
    assert.deepEqual(original.redirects, []);
    server.fail = false;
    original.tick();
    await settle();
    server.verified = true;
    original.window.fire('focus');
    await settle();
    assert.deepEqual(original.redirects, ['/dashboard']);
});

test('logging out or switching accounts stops the old verification watcher', async () => {
    for (const server of [{ userId: '7', status: 401 }, { userId: '8', verified: true }]) {
        const original = browser({ waiting: true, server });
        await settle();
        assert.deepEqual(original.redirects, []);
        assert.equal(original.timers.size, 0);
    }
});

test('Livewire navigation cleans up the watcher and returning can restart it', async () => {
    const server = { userId: '7', verified: false };
    const original = browser({ waiting: true, server });
    await settle();
    original.document.fire('livewire:navigating');
    assert.equal(original.timers.size, 0);
    server.verified = true;
    original.window.fire('focus');
    await settle();
    assert.deepEqual(original.redirects, []);
    original.document.fire('livewire:navigated');
    await settle();
    assert.deepEqual(original.redirects, ['/dashboard']);
});
