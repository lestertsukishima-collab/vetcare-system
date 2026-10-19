(() => {
    if (window.vetcareEmailVerification) return;
    window.vetcareEmailVerification = true;

    let cleanup = () => {};
    const openChannel = userId => {
        try {
            return new BroadcastChannel(`vetcare-email-verification:${userId}`);
        } catch {
            return null;
        }
    };

    const waitForVerification = element => {
        const { userId, statusUrl, redirectUrl } = element.dataset;
        const channel = openChannel(userId);
        let active = true;
        let pending = false;
        let timer;
        let handoffToken;
        let controller;

        const stop = () => {
            active = false;
            clearTimeout(timer);
            controller?.abort();
            channel?.close();
            window.removeEventListener('focus', check);
            document.removeEventListener('visibilitychange', onVisible);
        };
        const check = async () => {
            if (!active || pending) return;
            clearTimeout(timer);
            pending = true;
            controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const response = await fetch(statusUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!active) return;
                if (response.status === 401 || response.status === 403) {
                    stop();
                    return;
                }
                if (!response.ok) return;
                const status = await response.json();
                if (!active) return;
                if (status.user_id !== userId) {
                    stop();
                    return;
                }
                // A tab message is only a prompt to check; the server confirms verification.
                if (status.verified === true) {
                    if (handoffToken) channel?.postMessage({ type: 'continued', token: handoffToken });
                    stop();
                    window.location.replace(redirectUrl);
                }
            } catch {
                // Retry after a temporary network failure without interrupting the form.
            } finally {
                clearTimeout(timeout);
                pending = false;
                if (active) timer = setTimeout(check, 5000);
            }
        };
        const onVisible = () => { if (!document.hidden) check(); };
        if (channel) channel.onmessage = event => {
            if (event.data?.type !== 'verified' || typeof event.data.token !== 'string') return;
            handoffToken = event.data.token;
            check();
        };
        window.addEventListener('focus', check);
        document.addEventListener('visibilitychange', onVisible);
        check();
        return stop;
    };

    const continueInOriginalTab = userId => {
        const channel = openChannel(userId);
        if (!channel) return () => {};
        const token = `${Date.now()}-${Math.random()}`;
        const timer = setTimeout(() => channel.close(), 3000);
        channel.onmessage = event => {
            if (event.data?.type !== 'continued' || event.data.token !== token) return;
            clearTimeout(timer);
            channel.close();
            // Only close after the original tab confirms it is continuing. If the
            // browser disallows closing, this tab remains a usable dashboard.
            window.close();
        };
        channel.postMessage({ type: 'verified', token });
        return () => { clearTimeout(timer); channel.close(); };
    };

    const initialize = () => {
        cleanup();
        const waiting = document.querySelector('[data-email-verification]');
        const complete = document.querySelector('meta[name="email-verification-complete"]');
        cleanup = waiting ? waitForVerification(waiting)
            : complete ? continueInOriginalTab(complete.content) : () => {};
    };
    document.addEventListener('livewire:navigating', () => cleanup());
    document.addEventListener('livewire:navigated', initialize);
    window.addEventListener('pagehide', () => cleanup());
    window.addEventListener('pageshow', event => { if (event.persisted) initialize(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
