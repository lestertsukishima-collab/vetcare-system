export function installClinicEvents({ window, document }) {
    if (window.vetcareEvents) return;
    window.vetcareEvents = true;

    let pending = null;
    let navigation = 0;
    const notify = message => window.dispatchEvent(new window.CustomEvent('clinic-toast', { detail: { message } }));
    const setBusy = (button, busy) => {
        if (!button) return;
        button.disabled = busy;
        button.setAttribute('aria-busy', String(busy));
        button.setAttribute('aria-label', busy ? 'Refreshing workspace' : 'Refresh workspace');
        button.classList.toggle('is-refreshing', busy);
    };

    // Await the server response before announcing success or enabling another refresh.
    async function refreshWorkspace({ reconnected = false } = {}) {
        const workspace = document.querySelector('[data-clinic-workspace]');
        if (!workspace || pending) return false;
        if (window.navigator.onLine === false) {
            notify('You are offline. Reconnect before refreshing.');
            return false;
        }

        const button = document.querySelector('[data-workspace-refresh]');
        const request = { workspace, button, navigation };
        const isCurrent = () => request.navigation === navigation && workspace.isConnected
            && document.querySelector('[data-clinic-workspace]') === workspace;
        pending = request;
        setBusy(button, true);
        try {
            const component = window.Livewire?.find(workspace.getAttribute('wire:id'));
            if (!component) throw new Error('Workspace is not ready');
            await component.$call('refreshWorkspace');
            if (!isCurrent()) return false;
            notify(reconnected ? 'Connection restored. Workspace updated.' : 'Workspace updated.');
            return true;
        } catch {
            if (isCurrent()) notify('Could not refresh the workspace. Check your connection and try again.');
            return false;
        } finally {
            if (pending === request) {
                pending = null;
                setBusy(button, false);
            }
        }
    }

    document.addEventListener('click', async event => {
        if (!event.target.closest('[data-workspace-refresh]')) return;
        event.preventDefault();
        await refreshWorkspace();
    });

    window.addEventListener('online', async () => {
        await refreshWorkspace({ reconnected: true });
    });

    const invalidateRefresh = () => {
        navigation++;
        if (pending) setBusy(pending.button, false);
        pending = null;
    };
    document.addEventListener('livewire:navigating', invalidateRefresh);
    window.addEventListener('pagehide', invalidateRefresh);
}
