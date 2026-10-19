export function installClinicBootstrap({ window, document, Alert, Modal, Toast, Tooltip }) {
    if (window.vetcareBootstrap) return;
    window.vetcareBootstrap = { Alert, Modal, Toast, Tooltip };

    const tooltips = new Map();
    let livewireHooked = false;
    let selection = null;
    let pending = false;
    const $ = selector => document.querySelector(selector);
    const disposeTooltips = parent => {
        for (const [element, instance] of tooltips) {
            if (!parent || parent === element || parent.contains(element) || !element.isConnected) {
                instance.dispose();
                tooltips.delete(element);
            }
        }
    };
    const initialize = () => {
        for (const [element, instance] of tooltips) if (!element.isConnected) {
            instance.dispose();
            tooltips.delete(element);
        }
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => {
            if (!tooltips.has(element)) tooltips.set(element, Tooltip.getOrCreateInstance(element, {
                container: 'body', trigger: 'hover focus', html: false,
            }));
        });
        if (!livewireHooked && window.Livewire) {
            window.Livewire.hook('morph.removing', ({ el }) => disposeTooltips(el));
            window.Livewire.hook('morphed', initialize);
            livewireHooked = true;
        }
    };

    document.addEventListener('show.bs.modal', event => {
        if (event.target.id !== 'clinic-delete-modal') return;
        const trigger = event.relatedTarget;
        const componentId = trigger?.closest('[wire\\:id]')?.getAttribute('wire:id');
        const recordId = Number(trigger?.dataset.deleteId);
        if (!componentId || !Number.isSafeInteger(recordId) || recordId <= 0) {
            event.preventDefault();
            return;
        }
        selection = { componentId, recordId };
        $('[data-delete-name]').textContent = trigger.dataset.deleteLabel;
        $('[data-delete-error]').hidden = true;
        $('[data-delete-confirm]').disabled = false;
        $('[data-delete-confirm]').textContent = 'Delete record';
    });
    document.addEventListener('hide.bs.modal', event => {
        if (event.target.id === 'clinic-delete-modal' && pending) event.preventDefault();
    });
    document.addEventListener('hidden.bs.modal', event => {
        if (event.target.id === 'clinic-delete-modal') selection = null;
    });
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-delete-confirm]');
        if (!button || !selection || pending) return;
        const deleting = selection;
        pending = true;
        button.disabled = true;
        button.textContent = 'Deleting…';
        try {
            const component = window.Livewire?.find(deleting.componentId);
            if (!component) throw new Error('Component no longer available');
            await component.$call('deleteRecord', deleting.recordId);
            if (selection === deleting) {
                pending = false;
                Modal.getInstance($('#clinic-delete-modal'))?.hide();
            }
        } catch {
            if (selection === deleting) $('[data-delete-error]').hidden = false;
        } finally {
            if (selection === deleting) pending = false;
            button.disabled = false;
            button.textContent = 'Delete record';
        }
    });
    window.addEventListener('clinic-toast', event => {
        const element = $('[data-toast]');
        if (!element || typeof event.detail?.message !== 'string') return;
        $('[data-toast-message]').textContent = event.detail.message;
        Toast.getOrCreateInstance(element, { delay: 5000, autohide: true }).show();
    });
    document.addEventListener('livewire:navigating', () => {
        disposeTooltips();
        selection = null;
        pending = false;
        const modal = $('#clinic-delete-modal');
        if (modal) {
            const instance = Modal.getInstance(modal);
            instance?.hide();
            instance?.dispose();
        }
        const toast = $('[data-toast]');
        if (toast) {
            const instance = Toast.getInstance(toast);
            instance?.hide();
            instance?.dispose();
        }
    });
    document.addEventListener('livewire:init', initialize);
    document.addEventListener('livewire:navigated', initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
}
