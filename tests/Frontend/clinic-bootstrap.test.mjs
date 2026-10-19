import assert from 'node:assert/strict';
import test from 'node:test';
import { installClinicBootstrap } from '../../resources/js/clinic-bootstrap-ui.js';

function browser({ deleteRecord = async () => {} } = {}) {
    const events = () => {
        const handlers = new Map();
        return {
            addEventListener(type, callback) {
                if (!handlers.has(type)) handlers.set(type, []);
                handlers.get(type).push(callback);
            },
            fire(type, event = {}) {
                event.preventDefault ??= () => { event.defaultPrevented = true; };
                return Promise.all((handlers.get(type) ?? []).map(callback => callback(event)));
            },
        };
    };
    const element = (extra = {}) => ({ isConnected: true, hidden: false, textContent: '', disabled: false, contains: () => false, ...extra });
    const modal = element({ id: 'clinic-delete-modal' });
    const confirm = element({ closest: selector => selector === '[data-delete-confirm]' ? confirm : null });
    const name = element();
    const error = element({ hidden: true });
    const toast = element();
    const message = element();
    const hint = element();
    const trigger = element({
        dataset: { deleteId: '42', deleteLabel: 'Milo <the cat>' },
        closest: selector => {
            assert.equal(selector, '[wire\\:id]');
            return { getAttribute: () => 'workspace-1' };
        },
    });
    const document = {
        ...events(), readyState: 'complete',
        querySelector: selector => ({
            '#clinic-delete-modal': modal, '[data-delete-confirm]': confirm,
            '[data-delete-name]': name, '[data-delete-error]': error,
            '[data-toast]': toast, '[data-toast-message]': message,
        })[selector] ?? null,
        querySelectorAll: () => hint.isConnected ? [hint] : [],
    };
    const calls = [];
    const hooks = new Map();
    const window = { ...events(), Livewire: {
        hook: (name, callback) => hooks.set(name, callback),
        find: id => ({ $call: async (method, recordId) => {
            calls.push({ id, method, recordId });
            await deleteRecord();
        } }),
    } };
    const plugins = () => {
        const instances = new Map();
        return {
            getOrCreateInstance(element, options) {
                if (!instances.has(element)) instances.set(element, {
                    options, shown: 0, hidden: 0, disposed: 0,
                    show() { this.shown++; },
                    hide() { this.hidden++; },
                    dispose() { this.disposed++; instances.delete(element); },
                });
                return instances.get(element);
            },
            getInstance: element => instances.get(element),
        };
    };
    const Modal = plugins();
    const Toast = plugins();
    const Tooltip = plugins();
    const modalInstance = Modal.getOrCreateInstance(modal);
    installClinicBootstrap({ window, document, Modal, Toast, Tooltip, Alert: {} });
    return { document, window, calls, hooks, modal, modalInstance, confirm, name, error, toast, message, hint, Toast, Tooltip,
        open: () => document.fire('show.bs.modal', { target: modal, relatedTarget: trigger }),
        confirmDelete: () => document.fire('click', { target: confirm }),
    };
}

test('opening or cancelling a delete confirmation never deletes a record', async () => {
    const ui = browser();
    await ui.open();
    assert.equal(ui.name.textContent, 'Milo <the cat>');
    assert.deepEqual(ui.calls, []);
    await ui.document.fire('hidden.bs.modal', { target: ui.modal });
    await ui.confirmDelete();
    assert.deepEqual(ui.calls, []);
});

test('deletion requires confirmation and duplicate clicks cannot submit twice', async () => {
    let finish;
    const ui = browser({ deleteRecord: () => new Promise(resolve => { finish = resolve; }) });
    await ui.open();
    const deleting = ui.confirmDelete();
    assert.equal(ui.confirm.disabled, true);
    await ui.confirmDelete();
    assert.deepEqual(ui.calls, [{ id: 'workspace-1', method: 'deleteRecord', recordId: 42 }]);
    const hide = { target: ui.modal };
    await ui.document.fire('hide.bs.modal', hide);
    assert.equal(hide.defaultPrevented, true);
    finish();
    await deleting;
    assert.equal(ui.confirm.disabled, false);
    assert.equal(ui.modalInstance.hidden, 1);
});

test('a failed deletion remains recoverable and does not claim success', async () => {
    const ui = browser({ deleteRecord: async () => { throw new Error('Offline'); } });
    await ui.open();
    await ui.confirmDelete();
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.confirm.disabled, false);
    assert.equal(ui.modalInstance.hidden, 0);
    assert.equal(ui.Toast.getInstance(ui.toast), undefined);
});

test('notifications reuse Bootstrap toast and render server messages as text', async () => {
    const ui = browser();
    await ui.window.fire('clinic-toast', { detail: { message: '<img src=x> saved' } });
    const toast = ui.Toast.getInstance(ui.toast);
    assert.equal(ui.message.textContent, '<img src=x> saved');
    assert.equal(toast.options.autohide, true);
    await ui.window.fire('clinic-toast', { detail: { message: 'Appointment confirmed.' } });
    assert.equal(ui.Toast.getInstance(ui.toast), toast);
    assert.equal(toast.shown, 2);
});

test('tooltips are disposed before Livewire removes triggers and navigation clears overlays', async () => {
    const ui = browser();
    const tooltip = ui.Tooltip.getInstance(ui.hint);
    assert.equal(tooltip.options.trigger, 'hover focus');
    assert.equal(tooltip.options.html, false);
    ui.hooks.get('morph.removing')({ el: ui.hint });
    assert.equal(tooltip.disposed, 1);
    ui.hint.isConnected = false;
    ui.hooks.get('morphed')();
    await ui.open();
    await ui.window.fire('clinic-toast', { detail: { message: 'Saved.' } });
    const toast = ui.Toast.getInstance(ui.toast);
    await ui.document.fire('livewire:navigating');
    assert.equal(ui.modalInstance.disposed, 1);
    assert.equal(toast.disposed, 1);
    await ui.confirmDelete();
    assert.deepEqual(ui.calls, []);
});
