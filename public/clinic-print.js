(() => {
    let pending = false;
    const feedback = message => {
        const target = document.querySelector('[data-print-feedback]');
        if (target) target.textContent = message;
    };
    const print = async () => {
        if (pending) return;
        pending = true;
        document.querySelectorAll('[data-print]').forEach(button => { button.disabled = true; });
        try {
            // Defer runs after the document and blocking stylesheets are ready.
            // Fonts can still be loading; wait for them before browser pagination.
            if (document.fonts?.ready) await document.fonts.ready.catch(() => {});
            window.print();
            feedback('Use Print / Save PDF to open the print dialog again, or go back to the workspace.');
        } catch {
            feedback('The print dialog could not open. Use your browser’s Print command or press Ctrl + P.');
        } finally {
            pending = false;
            document.querySelectorAll('[data-print]').forEach(button => { button.disabled = false; });
        }
    };
    document.addEventListener('click', event => {
        if (event.target.closest('[data-print]')) void print();
    });
    const initialize = () => {
        if (document.body.hasAttribute('data-auto-print')) void print();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
