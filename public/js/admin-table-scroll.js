(() => {
    if (window.__adminTableScrollEnhancer) {
        return;
    }

    window.__adminTableScrollEnhancer = true;

    const state = {
        activeContainer: null,
        containers: [],
        frame: null,
        syncingFromFloatingBar: false,
    };

    const floatingScrollbar = document.createElement('div');
    floatingScrollbar.className = 'admin-table-floating-scrollbar';
    floatingScrollbar.setAttribute('aria-hidden', 'true');

    const floatingContent = document.createElement('div');
    floatingContent.className = 'admin-table-floating-scrollbar__content';
    floatingScrollbar.append(floatingContent);
    document.body.append(floatingScrollbar);

    const resizeObserver = new ResizeObserver(() => scheduleUpdate());

    const mutationObserver = new MutationObserver((mutations) => {
        const hasTableMutation = mutations.some(
            (mutation) => ! floatingScrollbar.contains(mutation.target),
        );

        if (hasTableMutation) {
            scheduleUpdate(true);
        }
    });

    function isWide(container) {
        return container.scrollWidth > container.clientWidth + 1;
    }

    function isInViewport(container) {
        const rect = container.getBoundingClientRect();

        return rect.bottom > 0 && rect.top < window.innerHeight;
    }

    function visibleHeight(container) {
        const rect = container.getBoundingClientRect();
        const top = Math.max(rect.top, 0);
        const bottom = Math.min(rect.bottom, window.innerHeight);

        return Math.max(0, bottom - top);
    }

    function registerContainer(container) {
        if (container.dataset.adminTableScrollEnhanced === 'true') {
            return;
        }

        container.dataset.adminTableScrollEnhanced = 'true';

        const activate = () => {
            state.activeContainer = container;
            scheduleUpdate();
        };

        container.addEventListener('pointerenter', activate);
        container.addEventListener('focusin', activate);
        container.addEventListener('scroll', () => {
            if (! state.syncingFromFloatingBar && state.activeContainer === container) {
                floatingScrollbar.scrollLeft = container.scrollLeft;
            }

            scheduleUpdate();
        }, { passive: true });
    }

    function scanContainers() {
        const containers = Array.from(
            document.querySelectorAll('.fi-ta-content-ctn'),
        ).filter((container) => container.querySelector('.fi-ta-table > thead'));

        resizeObserver.disconnect();

        containers.forEach((container) => {
            registerContainer(container);
            resizeObserver.observe(container);
        });

        state.containers = containers;

        if (! containers.includes(state.activeContainer)) {
            state.activeContainer = null;
        }
    }

    function selectActiveContainer() {
        const candidates = state.containers.filter(
            (container) => isWide(container) && isInViewport(container),
        );

        if (candidates.includes(state.activeContainer)) {
            return state.activeContainer;
        }

        return candidates.sort(
            (first, second) => visibleHeight(second) - visibleHeight(first),
        )[0] ?? null;
    }

    function hideFloatingScrollbar() {
        floatingScrollbar.classList.remove('is-visible');
    }

    function updateFloatingScrollbar() {
        state.frame = null;

        const container = selectActiveContainer();
        state.activeContainer = container;

        if (! container || window.innerWidth < 768) {
            hideFloatingScrollbar();

            return;
        }

        const rect = container.getBoundingClientRect();
        const visibleLeft = Math.max(0, rect.left);
        const visibleRight = Math.min(window.innerWidth, rect.right);
        const visibleWidth = Math.max(0, visibleRight - visibleLeft);
        const nativeScrollbarIsVisible = rect.bottom > 0 && rect.bottom <= window.innerHeight;

        if (visibleWidth <= 0 || nativeScrollbarIsVisible) {
            hideFloatingScrollbar();

            return;
        }

        floatingContent.style.width = `${container.scrollWidth}px`;
        floatingScrollbar.style.left = `${visibleLeft}px`;
        floatingScrollbar.style.width = `${visibleWidth}px`;

        if (! state.syncingFromFloatingBar) {
            floatingScrollbar.scrollLeft = container.scrollLeft;
        }

        floatingScrollbar.classList.add('is-visible');
    }

    function scheduleUpdate(rescan = false) {
        if (rescan) {
            scanContainers();
        }

        if (state.frame !== null) {
            return;
        }

        state.frame = window.requestAnimationFrame(updateFloatingScrollbar);
    }

    floatingScrollbar.addEventListener('scroll', () => {
        if (! state.activeContainer) {
            return;
        }

        state.syncingFromFloatingBar = true;
        state.activeContainer.scrollLeft = floatingScrollbar.scrollLeft;
        state.syncingFromFloatingBar = false;
    }, { passive: true });

    window.addEventListener('scroll', () => scheduleUpdate(), { passive: true });
    window.addEventListener('resize', () => scheduleUpdate(true), { passive: true });
    document.addEventListener('livewire:navigated', () => scheduleUpdate(true));

    mutationObserver.observe(document.body, {
        childList: true,
        subtree: true,
    });

    scanContainers();
    scheduleUpdate();
})();
