(() => {
    if (window.__adminTableScrollEnhancer) return;
    window.__adminTableScrollEnhancer = true;

    const tables = new Map();
    let frame = null;
    let needsRescan = false;
    let livewireHookRegistered = false;
    const resizeObserver = new ResizeObserver(() => scheduleUpdate());

    function setStyle(element, property, value) {
        if (element.style.getPropertyValue(property) !== value) element.style.setProperty(property, value);
    }

    function register(container) {
        const host = container.closest('.fi-ta-main') || container.parentElement;
        const scrollbar = document.createElement('div');
        scrollbar.className = 'admin-table-scrollbar';
        scrollbar.setAttribute('wire:ignore', '');
        scrollbar.setAttribute('role', 'region');
        scrollbar.setAttribute('aria-label', 'Táblázat vízszintes görgetése');
        scrollbar.tabIndex = 0;
        const content = document.createElement('div');
        content.className = 'admin-table-scrollbar__content';
        scrollbar.append(content);
        // A real flow item AFTER pagination/filters: it can never cover the last row.
        host.append(scrollbar);
        container.dataset.adminTableScrollEnhanced = 'true';
        const entry = { container, host, scrollbar, content, syncing: false };
        tables.set(container, entry);

        container.addEventListener('scroll', () => {
            if (!entry.syncing) scrollbar.scrollLeft = container.scrollLeft;
        }, { passive: true });
        scrollbar.addEventListener('scroll', () => {
            entry.syncing = true;
            container.scrollLeft = scrollbar.scrollLeft;
            entry.syncing = false;
        }, { passive: true });
        return entry;
    }

    function scanContainers() {
        const containers = Array.from(document.querySelectorAll('.fi-ta-content-ctn'))
            .filter(container => container.querySelector('.fi-ta-table > thead'));
        for (const [container, entry] of tables) {
            if (!containers.includes(container)) {
                entry.scrollbar.remove();
                tables.delete(container);
            }
        }
        resizeObserver.disconnect();
        for (const container of containers) {
            const entry = tables.get(container) || register(container);
            if (pricingPage(entry) && !entry.pricingWheelRegistered) {
                // Only the pricing grid hands the first downward wheel movement
                // to the page, so its introduction scrolls away before its rows.
                container.addEventListener('wheel', event => scrollPricingIntro(entry, event), { passive: false });
                entry.pricingWheelRegistered = true;
            }
            // Morphing may strip client-added attributes without replacing the node.
            container.dataset.adminTableScrollEnhanced = 'true';
            // Livewire may remove the injected node or add pagination after it.
            if (entry.scrollbar.parentElement !== entry.host || entry.host.lastElementChild !== entry.scrollbar) {
                entry.host.append(entry.scrollbar);
            }
            resizeObserver.observe(container);
            resizeObserver.observe(container.querySelector('.fi-ta-table'));
            for (const child of entry.host.children) {
                if (child !== container && child !== entry.scrollbar) resizeObserver.observe(child);
            }
        }
    }

    function viewportBounds(container) {
        let top = 0;
        let bottom = window.visualViewport?.height || window.innerHeight;
        // A relation table can be clipped by a modal's independently scrolling body.
        for (let parent = container.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
            // Do not use the table card's own content-dependent height as a
            // constraint: that would shrink it again on every ResizeObserver tick.
            if (!parent.matches('.fi-modal-content')) continue;
            const rect = parent.getBoundingClientRect();
            top = Math.max(top, rect.top);
            bottom = Math.min(bottom, rect.bottom);
        }
        return { top, bottom };
    }

    function pricingPage(entry) {
        if (entry.container.closest('.fi-modal-content')) return null;
        const page = entry.container.closest('.fi-page');
        return page?.querySelector('[data-pricing-page-scroll]') ? page : null;
    }

    function pricingViewportTop(bounds) {
        const topbar = document.querySelector('.fi-topbar');
        return Math.max(bounds.top, topbar ? topbar.getBoundingClientRect().bottom : 0);
    }

    function scrollPricingIntro(entry, event) {
        if (!pricingPage(entry) || event.cancelable === false || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey
            || event.deltaY <= 0 || Math.abs(event.deltaX) > Math.abs(event.deltaY)
            || event.target?.closest?.('textarea, [contenteditable="true"], [role="listbox"], .fi-dropdown-panel')) return;

        const root = document.scrollingElement;
        if (!root) return;
        const distance = entry.host.getBoundingClientRect().top - pricingViewportTop(viewportBounds(entry.container)) - 8;
        const remaining = root.scrollHeight - root.clientHeight - root.scrollTop;
        if (distance <= 1 || remaining <= 0) return;

        const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? entry.container.clientHeight : 1;
        const delta = event.deltaY * unit;
        const consumed = Math.min(delta, distance, remaining);
        event.preventDefault();
        root.scrollTop += consumed;
        // Do not discard a large wheel delta that crosses the page/grid boundary.
        if (consumed >= distance - 1 && delta > consumed) entry.container.scrollTop += delta - consumed;
    }

    function updateTable(entry) {
        const { container, scrollbar, content } = entry;
        // Hidden tabs/modals must not contribute zero-width measurements.
        if (!container.getClientRects().length) {
            scrollbar.classList.remove('is-visible');
            return;
        }
        const wide = container.scrollWidth > container.clientWidth + 1;
        scrollbar.classList.toggle('is-visible', wide);

        const rect = container.getBoundingClientRect();
        const bounds = viewportBounds(container);
        const barRect = scrollbar.getBoundingClientRect();
        const footerHeight = Math.max(0, (wide ? barRect.top : entry.host.getBoundingClientRect().bottom) - rect.bottom);
        const barHeight = wide ? barRect.height : 0;
        const headHeight = container.querySelector('thead')?.getBoundingClientRect().height || 0;
        const minimumHeight = Math.max(64, headHeight + 28);
        const pricing = pricingPage(entry);
        const toolbarHeight = Math.max(0, rect.top - entry.host.getBoundingClientRect().top);
        // Pricing pages must be taller than the viewport when they have an intro.
        // Measuring only the space BELOW that intro trapped it permanently above
        // a tiny inner list. A full workspace allows normal outer page scrolling.
        const top = pricing ? pricingViewportTop(bounds) + toolbarHeight + 8 : Math.max(bounds.top, rect.top);
        const maximumHeight = pricing ? Infinity : Math.max(minimumHeight, bounds.bottom - bounds.top - 160);
        const availableHeight = bounds.bottom - top - footerHeight - barHeight - 8;
        setStyle(container, '--admin-table-content-max-height', Math.floor(Math.max(minimumHeight, Math.min(maximumHeight, availableHeight))) + 'px');

        if (wide) {
            // Equal scroll ranges, even with a vertical scrollbar consuming table width.
            setStyle(content, 'width', (scrollbar.clientWidth + Math.max(0, container.scrollWidth - container.clientWidth)) + 'px');
            if (!entry.syncing) scrollbar.scrollLeft = container.scrollLeft;
        }
    }

    function scheduleUpdate(rescan = false) {
        needsRescan ||= rescan;
        if (frame !== null) return;
        frame = window.requestAnimationFrame(() => {
            frame = null;
            if (needsRescan) {
                needsRescan = false;
                scanContainers();
            }
            for (const entry of tables.values()) updateTable(entry);
        });
    }

    function registerLivewireHook() {
        if (!window.Livewire || livewireHookRegistered) return;
        livewireHookRegistered = true;
        window.Livewire.hook('morphed', () => scheduleUpdate(true));
    }

    new MutationObserver(mutations => {
        const changed = mutations.some(mutation => !mutation.target.closest?.('.admin-table-scrollbar')
            && [...mutation.addedNodes, ...mutation.removedNodes].some(node => node.nodeType !== 1 || !node.matches('.admin-table-scrollbar')));
        if (changed) scheduleUpdate(true);
    }).observe(document.body, { childList: true, subtree: true });

    // The bar is in normal flow, so it already moves with its table. Resizing
    // the inner list on outer scrolling makes it grow against the user's wheel
    // movement and delays the native hand-off at the list's top/bottom edge.
    // Recalculate only for actual layout/data/viewport changes, never scrolling.
    window.addEventListener('resize', () => scheduleUpdate(true), { passive: true });
    window.visualViewport?.addEventListener('resize', () => scheduleUpdate(), { passive: true });
    document.addEventListener('livewire:navigated', () => scheduleUpdate(true));
    document.addEventListener('livewire:init', registerLivewireHook);
    registerLivewireHook();

    scanContainers();
    scheduleUpdate();
})();
