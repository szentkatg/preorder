(() => {
    if (window.__adminTableScrollEnhancer) {
        return;
    }

    window.__adminTableScrollEnhancer = true;

    const state = {
        activeContainer: null,
        containers: [],
        frame: null,
        stickySource: null,
        stickyDirty: true,
        syncingFromFloatingBar: false,
    };

    const stickyHeader = document.createElement('div');
    stickyHeader.className = 'admin-table-sticky-header';
    stickyHeader.setAttribute('aria-hidden', 'true');

    const stickyTrack = document.createElement('div');
    stickyTrack.className = 'admin-table-sticky-header__track';
    stickyHeader.append(stickyTrack);

    const floatingScrollbar = document.createElement('div');
    floatingScrollbar.className = 'admin-table-floating-scrollbar';
    floatingScrollbar.setAttribute('aria-hidden', 'true');

    const floatingContent = document.createElement('div');
    floatingContent.className = 'admin-table-floating-scrollbar__content';
    floatingScrollbar.append(floatingContent);

    document.body.append(stickyHeader, floatingScrollbar);

    const resizeObserver = new ResizeObserver(() => {
        state.stickyDirty = true;
        scheduleUpdate();
    });

    const mutationObserver = new MutationObserver((mutations) => {
        const hasTableMutation = mutations.some((mutation) => (
            ! stickyHeader.contains(mutation.target)
            && ! floatingScrollbar.contains(mutation.target)
        ));

        if (! hasTableMutation) {
            return;
        }

        state.stickyDirty = true;
        scheduleUpdate(true);
    });

    function isWide(container) {
        return container.scrollWidth > container.clientWidth + 1;
    }

    function isInViewport(container, topOffset) {
        const rect = container.getBoundingClientRect();

        return rect.bottom > topOffset && rect.top < window.innerHeight;
    }

    function getTopOffset() {
        const topbar = document.querySelector('.fi-topbar-ctn');

        if (! topbar) {
            return 0;
        }

        const rect = topbar.getBoundingClientRect();

        return Math.max(0, Math.min(window.innerHeight, rect.bottom));
    }

    function visibleHeight(container, topOffset) {
        const rect = container.getBoundingClientRect();
        const top = Math.max(rect.top, topOffset);
        const bottom = Math.min(rect.bottom, window.innerHeight);

        return Math.max(0, bottom - top);
    }

    function registerContainer(container) {
        if (container.dataset.adminTableScrollEnhanced === 'true') {
            return;
        }

        container.dataset.adminTableScrollEnhanced = 'true';

        container.addEventListener('pointerenter', () => {
            state.activeContainer = container;
            state.stickyDirty = true;
            scheduleUpdate();
        });

        container.addEventListener('focusin', () => {
            state.activeContainer = container;
            state.stickyDirty = true;
            scheduleUpdate();
        });

        container.addEventListener('scroll', () => {
            if (! state.syncingFromFloatingBar && state.activeContainer === container) {
                floatingScrollbar.scrollLeft = container.scrollLeft;
            }

            scheduleUpdate();
        }, { passive: true });
    }

    function scanContainers() {
        const containers = Array.from(document.querySelectorAll('.fi-ta-content-ctn'));

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

    function selectActiveContainer(topOffset) {
        const candidates = state.containers.filter(
            (container) => isWide(container) && isInViewport(container, topOffset),
        );

        if (candidates.includes(state.activeContainer)) {
            return state.activeContainer;
        }

        return candidates.sort(
            (first, second) => visibleHeight(second, topOffset) - visibleHeight(first, topOffset),
        )[0] ?? null;
    }

    function tableWithHeader(container) {
        return Array.from(container.querySelectorAll('table.fi-ta-table'))
            .find((table) => table.tHead);
    }

    function sanitizeClone(element) {
        element.querySelectorAll('*').forEach((child) => {
            const isHiddenLoadingElement = Array.from(child.attributes).some(
                (attribute) => (
                    attribute.name.startsWith('wire:loading')
                    && ! attribute.name.includes('.remove')
                ),
            );

            if (isHiddenLoadingElement) {
                child.style.display = 'none';
            }

            Array.from(child.attributes).forEach((attribute) => {
                if (
                    attribute.name === 'id'
                    || attribute.name.startsWith('wire:')
                    || (attribute.name.startsWith('x-') && attribute.name !== 'x-cloak')
                ) {
                    child.removeAttribute(attribute.name);
                }
            });

            child.removeAttribute('tabindex');

            if (child.matches('input, button, select, textarea')) {
                child.setAttribute('disabled', 'disabled');
            }
        });
    }

    function rebuildStickyHeader(table) {
        const sourceHead = table.tHead;
        const clonedTable = table.cloneNode(false);
        const clonedHead = sourceHead.cloneNode(true);
        const sourceCells = sourceHead.querySelectorAll('th');
        const clonedCells = clonedHead.querySelectorAll('th');
        const tableWidth = Math.max(table.scrollWidth, table.getBoundingClientRect().width);

        clonedTable.removeAttribute('wire:key');
        clonedTable.classList.add('admin-table-sticky-header__table');
        clonedTable.style.width = `${tableWidth}px`;
        clonedTable.style.minWidth = `${tableWidth}px`;

        clonedCells.forEach((cell, index) => {
            const sourceCell = sourceCells[index];

            if (! sourceCell) {
                return;
            }

            const width = sourceCell.getBoundingClientRect().width;
            cell.style.width = `${width}px`;
            cell.style.minWidth = `${width}px`;
            cell.style.maxWidth = `${width}px`;
        });

        sanitizeClone(clonedHead);
        clonedTable.append(clonedHead);
        stickyTrack.replaceChildren(clonedTable);

        state.stickySource = sourceHead;
        state.stickyDirty = false;
    }

    function hideEnhancements() {
        stickyHeader.classList.remove('is-visible');
        floatingScrollbar.classList.remove('is-visible');
        state.stickySource = null;
    }

    function updateEnhancements() {
        state.frame = null;

        const topOffset = getTopOffset();
        const container = selectActiveContainer(topOffset);

        state.activeContainer = container;

        if (! container || window.innerWidth < 768) {
            hideEnhancements();

            return;
        }

        const containerRect = container.getBoundingClientRect();
        const visibleLeft = Math.max(0, containerRect.left);
        const visibleRight = Math.min(window.innerWidth, containerRect.right);
        const visibleWidth = Math.max(0, visibleRight - visibleLeft);
        const hiddenLeft = Math.max(0, visibleLeft - containerRect.left);
        const table = tableWithHeader(container);

        if (! table || visibleWidth <= 0) {
            hideEnhancements();

            return;
        }

        const sourceHead = table.tHead;
        const headRect = sourceHead.getBoundingClientRect();
        const tableRect = table.getBoundingClientRect();
        const shouldShowStickyHeader = (
            headRect.top < topOffset
            && tableRect.bottom > topOffset + headRect.height
        );

        if (shouldShowStickyHeader) {
            if (state.stickyDirty || state.stickySource !== sourceHead) {
                rebuildStickyHeader(table);
            }

            stickyHeader.style.left = `${visibleLeft}px`;
            stickyHeader.style.top = `${topOffset}px`;
            stickyHeader.style.width = `${visibleWidth}px`;
            stickyTrack.style.transform = `translateX(-${container.scrollLeft + hiddenLeft}px)`;
            stickyHeader.classList.add('is-visible');
        } else {
            stickyHeader.classList.remove('is-visible');
        }

        const nativeScrollbarIsVisible = (
            containerRect.bottom > 0
            && containerRect.bottom <= window.innerHeight
        );
        const shouldShowFloatingScrollbar = (
            ! nativeScrollbarIsVisible
            && containerRect.top < window.innerHeight - floatingScrollbar.offsetHeight
        );

        if (shouldShowFloatingScrollbar) {
            floatingContent.style.width = `${container.scrollWidth}px`;
            floatingScrollbar.style.left = `${visibleLeft}px`;
            floatingScrollbar.style.width = `${visibleWidth}px`;

            if (! state.syncingFromFloatingBar) {
                floatingScrollbar.scrollLeft = container.scrollLeft;
            }

            floatingScrollbar.classList.add('is-visible');
        } else {
            floatingScrollbar.classList.remove('is-visible');
        }
    }

    function scheduleUpdate(rescan = false) {
        if (rescan) {
            scanContainers();
        }

        if (state.frame !== null) {
            return;
        }

        state.frame = window.requestAnimationFrame(updateEnhancements);
    }

    floatingScrollbar.addEventListener('scroll', () => {
        if (! state.activeContainer) {
            return;
        }

        state.syncingFromFloatingBar = true;
        state.activeContainer.scrollLeft = floatingScrollbar.scrollLeft;
        state.syncingFromFloatingBar = false;
        scheduleUpdate();
    }, { passive: true });

    window.addEventListener('scroll', () => scheduleUpdate(), { passive: true });
    window.addEventListener('resize', () => {
        state.stickyDirty = true;
        scheduleUpdate(true);
    }, { passive: true });
    document.addEventListener('livewire:navigated', () => scheduleUpdate(true));

    mutationObserver.observe(document.body, {
        childList: true,
        subtree: true,
    });

    scanContainers();
    scheduleUpdate();
})();
