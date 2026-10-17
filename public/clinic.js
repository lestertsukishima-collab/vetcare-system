(() => {
    if (window.vetcareUI) return;
    window.vetcareUI = true;
    let toastTimer;
    let commandOpener;
    let navFocusFrame;
    const $ = selector => document.querySelector(selector);
    const mobileNavigation = () => matchMedia('(max-width: 1024px)').matches;
    const nav = (open, { restoreFocus = true } = {}) => {
        if (open && !mobileNavigation()) return;
        const wasOpen = document.body.classList.contains('nav-open');
        const sidebar = $('#clinic-navigation');
        cancelAnimationFrame(navFocusFrame);
        document.body.classList.toggle('nav-open', open);
        if ($('#main-content')) $('#main-content').inert = open;
        $('[data-nav-toggle]')?.setAttribute('aria-expanded', String(open));
        if (sidebar) {
            if (open) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
            }
        }
        if (open) navFocusFrame = requestAnimationFrame(() => {
            if (document.body.classList.contains('nav-open')) ($('.main-nav a.active') || sidebar?.querySelector('[data-nav-close]'))?.focus();
        });
        else if (wasOpen && restoreFocus) (mobileNavigation() ? $('[data-nav-toggle]') : $('.main-nav a.active'))?.focus({ preventScroll: true });
    };
    const command = open => {
        const dialog = $('[data-command-dialog]');
        if (!dialog) return;
        if (open && !dialog.open) {
            commandOpener = document.body.classList.contains('nav-open') ? $('[data-nav-toggle]') : document.activeElement;
            nav(false, { restoreFocus: false });
            dialog.showModal();
            $('[data-command-search]').value = '';
            filterCommands('');
            $('[data-command-search]').focus();
        } else if (!open) {
            dialog.close();
            commandOpener?.focus({ preventScroll: true });
        }
    };
    const filterCommands = value => {
        const needle = value.toLocaleLowerCase().trim();
        let count = 0;
        document.querySelectorAll('[data-command-link]').forEach(link => {
            link.hidden = !link.textContent.toLocaleLowerCase().includes(needle);
            if (!link.hidden) count++;
        });
        $('[data-command-empty]').hidden = count > 0;
    };
    const theme = () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        document.documentElement.classList.toggle('dark', next === 'dark');
        try { localStorage.setItem('vetcare-theme', next); localStorage.setItem('flux.appearance', next); } catch {}
    };
    // Keep wide tables inside their own scrolling region, including after Livewire updates.
    const scrollRegions = new Map();
    const scrollAttributes = ['tabindex', 'role', 'aria-label', 'aria-describedby'];
    let scrollHintId = 0;
    let scrollUpdatePending = false;
    let livewireHooked = false;
    const scheduleScrollRegions = () => {
        if (scrollUpdatePending) return;
        scrollUpdatePending = true;
        requestAnimationFrame(() => {
            scrollUpdatePending = false;
            syncScrollRegions();
        });
    };
    const scrollObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(scheduleScrollRegions) : null;
    const syncScrollRegions = () => {
        for (const [region, state] of scrollRegions) {
            if (region.isConnected) continue;
            scrollObserver?.unobserve(region);
            scrollObserver?.unobserve(state.table);
            state.hint.remove();
            scrollRegions.delete(region);
        }
        document.querySelectorAll('.scroll').forEach(region => {
            const table = region.querySelector('table');
            if (!table) return;
            let state = scrollRegions.get(region);
            if (!state) {
                const hint = document.createElement('p');
                hint.className = 'table-scroll-hint';
                hint.id = `clinic-scroll-hint-${++scrollHintId}`;
                hint.textContent = 'Scroll sideways to see all columns.';
                hint.hidden = true;
                state = { hint, table, attributes: Object.fromEntries(scrollAttributes.map(name => [name, region.getAttribute(name)])) };
                scrollRegions.set(region, state);
                scrollObserver?.observe(region);
                scrollObserver?.observe(table);
            } else if (state.table !== table) {
                scrollObserver?.unobserve(state.table);
                state.table = table;
                scrollObserver?.observe(table);
            }
            if (!state.hint.isConnected) region.insertAdjacentElement('afterend', state.hint);
            const overflowing = region.clientWidth > 0 && region.scrollWidth > region.clientWidth + 1;
            state.hint.hidden = !overflowing;
            if (overflowing) {
                const heading = region.closest('.panel, .medical-timeline-card')?.querySelector('h2, h3, h4')?.textContent.trim();
                region.setAttribute('tabindex', state.attributes.tabindex ?? '0');
                region.setAttribute('role', state.attributes.role ?? 'region');
                region.setAttribute('aria-label', state.attributes['aria-label'] ?? `${heading || 'Clinic'} table`);
                region.setAttribute('aria-describedby', [state.attributes['aria-describedby'], state.hint.id].filter(Boolean).join(' '));
            } else {
                for (const name of scrollAttributes) {
                    if (state.attributes[name] === null) region.removeAttribute(name);
                    else region.setAttribute(name, state.attributes[name]);
                }
            }
        });
    };
    const hookLivewire = () => {
        if (livewireHooked || !window.Livewire) return;
        window.Livewire.hook('morphed', scheduleScrollRegions);
        livewireHooked = true;
    };
    document.addEventListener('livewire:init', hookLivewire);
    document.addEventListener('toggle', scheduleScrollRegions, true);
    window.addEventListener('clinic-saved', scheduleScrollRegions);
    document.addEventListener('click', event => {
        const target = event.target.closest('button,a,dialog');
        if (!target) return;
        if (target.matches('[data-nav-toggle]')) nav(!document.body.classList.contains('nav-open'));
        if (target.matches('[data-nav-close]')) nav(false);
        if (target.matches('[data-command-open]')) command(true);
        if (target.matches('[data-command-close]')) command(false);
        if (target.matches('[data-command-link]')) command(false);
        if (target.matches('[data-command-dialog]') && event.target === target) {
            const bounds = target.getBoundingClientRect();
            if (event.clientY < bounds.top || event.clientY > bounds.bottom || event.clientX < bounds.left || event.clientX > bounds.right) command(false);
        }
        if (target.matches('[data-theme-toggle]')) theme();
        if (target.matches('[data-print]')) window.print();
        if (target.matches('[data-toast-close]')) $('[data-toast]').hidden = true;
        const anchor = event.target.closest('a[href]');
        if (anchor?.hash === '#create' && anchor.pathname === location.pathname) {
            const form = $('#create');
            if (form) form.open = true;
        }
        document.querySelectorAll('.user-menu[open]').forEach(menu => {
            if (!menu.contains(event.target)) menu.open = false;
        });
    });
    document.addEventListener('input', event => {
        if (event.target.matches('[data-command-search]')) filterCommands(event.target.value);
    });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            command(!$('[data-command-dialog]')?.open);
        }
        if (event.key === 'Escape' && document.body.classList.contains('nav-open')) nav(false);
        if (event.key === 'Tab' && document.body.classList.contains('nav-open')) {
            const sidebar = $('#clinic-navigation');
            const controls = [...sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')].filter(control => control.getClientRects().length > 0);
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (first && (!sidebar.contains(document.activeElement) || (event.shiftKey ? document.activeElement === first : document.activeElement === last))) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            }
        }
        if ($('[data-command-dialog]')?.open) {
            const links = [...document.querySelectorAll('[data-command-link]:not([hidden])')];
            const index = links.indexOf(document.activeElement);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const next = event.key === 'ArrowDown' ? (index + 1) % links.length : (index - 1 + links.length) % links.length;
                links[next]?.focus();
            }
            if (event.key === 'Enter' && event.target.matches('[data-command-search]')) {
                event.preventDefault();
                links[0]?.click();
            }
        }
    });
    window.addEventListener('clinic-toast', event => {
        const toast = $('[data-toast]');
        if (!toast) return;
        $('[data-toast-message]').textContent = event.detail.message;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.hidden = true; }, 5000);
    });
    const initialize = () => {
        nav(false, { restoreFocus: false });
        hookLivewire();
        scheduleScrollRegions();
        try {
            const appearance = localStorage.getItem('flux.appearance') || localStorage.getItem('vetcare-theme') || 'light';
            document.documentElement.dataset.theme = appearance === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : appearance;
        } catch {}
        if (location.hash === '#create' && $('#create')) {
            $('#create').open = true;
            requestAnimationFrame(() => $('#create')?.scrollIntoView({ block: 'start' }));
        }
    };
    document.addEventListener('livewire:navigated', initialize);
    window.addEventListener('resize', () => {
        if (!mobileNavigation() && document.body.classList.contains('nav-open')) nav(false);
        scheduleScrollRegions();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
