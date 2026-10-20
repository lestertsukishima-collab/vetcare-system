import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/clinic-print.js', import.meta.url), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

function preview({ readyState = 'complete', automatic = true, fonts = Promise.resolve(), fail = false } = {}) {
    const events = new Map();
    const button = { disabled: false };
    const feedback = { textContent: '' };
    let printCalls = 0;
    const document = {
        readyState,
        fonts: fonts === null ? undefined : { ready: fonts },
        body: { hasAttribute: name => automatic && name === 'data-auto-print' },
        addEventListener: (name, fn) => events.set(name, fn),
        querySelector: () => feedback,
        querySelectorAll: () => [button],
    };
    const window = { print() { printCalls++; if (fail) throw new Error('Unavailable'); } };
    vm.runInNewContext(source, { document, window });
    return {
        button, feedback, calls: () => printCalls,
        ready: () => events.get('DOMContentLoaded')?.(),
        click: (matches = true) => events.get('click')({ target: { closest: () => matches ? button : null } }),
    };
}

test('automatic printing waits for document readiness and fonts', async () => {
    let finishFonts;
    const page = preview({ readyState: 'loading', fonts: new Promise(resolve => { finishFonts = resolve; }) });
    assert.equal(page.calls(), 0);
    page.ready();
    assert.equal(page.button.disabled, true);
    assert.equal(page.calls(), 0);
    finishFonts();
    await tick();
    assert.equal(page.calls(), 1);
    assert.equal(page.button.disabled, false);
    assert.match(page.feedback.textContent, /open the print dialog again/);
});

test('manual printing supports nested button targets and ignores unrelated clicks', async () => {
    const page = preview({ automatic: false });
    await tick();
    page.click(false);
    assert.equal(page.calls(), 0);
    page.click();
    await tick();
    assert.equal(page.calls(), 1);
    page.click();
    await tick();
    assert.equal(page.calls(), 2);
});

test('repeated clicks cannot queue duplicate dialogs while fonts are loading', async () => {
    let finish;
    const page = preview({ fonts: new Promise(resolve => { finish = resolve; }) });
    page.click();
    page.click();
    finish();
    await tick();
    assert.equal(page.calls(), 1);
});

test('print failure gives a browser shortcut and allows retry', async () => {
    const page = preview({ fail: true });
    await tick();
    assert.equal(page.button.disabled, false);
    assert.match(page.feedback.textContent, /Ctrl \+ P/);
    page.click();
    await tick();
    assert.equal(page.calls(), 2);
});

test('a failed font load or unavailable Font Loading API does not prevent printing', async () => {
    for (const fonts of [Promise.reject(new Error('Font failed')), null]) {
        const page = preview({ fonts });
        await tick();
        assert.equal(page.calls(), 1);
        assert.equal(page.button.disabled, false);
    }
});
