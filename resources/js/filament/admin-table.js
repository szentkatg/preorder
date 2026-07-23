(() => {
    const initializedTables = new WeakSet();

    function initializeTables() {
        document.querySelectorAll('.fi-ta-content').forEach((tableContainer) => {
            if (initializedTables.has(tableContainer)) {
                refreshTable(tableContainer);
                return;
            }

            const table = tableContainer.querySelector('table');

            if (!table) {
                return;
            }

            initializedTables.add(tableContainer);

            const topContainer = document.createElement('div');
            topContainer.className = 'ht-table-top-container';

            const headerCopy = document.createElement('div');
            headerCopy.className = 'ht-table-header-copy';

            const topScroll = document.createElement('div');
            topScroll.className = 'ht-table-top-scroll';

            const topScrollInner = document.createElement('div');
            topScrollInner.className = 'ht-table-top-scroll-inner';

            topScroll.appendChild(topScrollInner);
            topContainer.appendChild(headerCopy);
            topContainer.appendChild(topScroll);

            tableContainer.parentElement.insertBefore(
                topContainer,
                tableContainer,
            );

            let synchronizing = false;

            topScroll.addEventListener('scroll', () => {
                if (synchronizing) {
                    return;
                }

                synchronizing = true;

                tableContainer.scrollLeft = topScroll.scrollLeft;
                headerCopy.scrollLeft = topScroll.scrollLeft;

                synchronizing = false;
            });

            tableContainer.addEventListener('scroll', () => {
                if (synchronizing) {
                    return;
                }

                synchronizing = true;

                topScroll.scrollLeft = tableContainer.scrollLeft;
                headerCopy.scrollLeft = tableContainer.scrollLeft;

                synchronizing = false;
            });

            tableContainer._htTableElements = {
                topContainer,
                headerCopy,
                topScroll,
                topScrollInner,
            };

            const resizeObserver = new ResizeObserver(() => {
                refreshTable(tableContainer);
            });

            resizeObserver.observe(tableContainer);
            resizeObserver.observe(table);

            refreshTable(tableContainer);
        });
    }

    function refreshTable(tableContainer) {
        const table = tableContainer.querySelector('table');
        const thead = table?.querySelector('thead');
        const elements = tableContainer._htTableElements;

        if (!table || !thead || !elements) {
            return;
        }

        const hasHorizontalOverflow =
            table.scrollWidth > tableContainer.clientWidth + 1;

        elements.topContainer.classList.toggle(
            'ht-visible',
            hasHorizontalOverflow,
        );

        if (!hasHorizontalOverflow) {
            elements.headerCopy.replaceChildren();
            return;
        }

        const tableWidth = table.scrollWidth;

        elements.topScrollInner.style.width = `${tableWidth}px`;

        const copiedTable = document.createElement('table');
        copiedTable.className = table.className;
        copiedTable.style.width = `${tableWidth}px`;
        copiedTable.style.minWidth = `${tableWidth}px`;

        const copiedHead = thead.cloneNode(true);

        const originalHeaderCells = thead.querySelectorAll('th');
        const copiedHeaderCells = copiedHead.querySelectorAll('th');

        originalHeaderCells.forEach((cell, index) => {
            const copiedCell = copiedHeaderCells[index];

            if (!copiedCell) {
                return;
            }

            const width = cell.getBoundingClientRect().width;

            copiedCell.style.width = `${width}px`;
            copiedCell.style.minWidth = `${width}px`;
            copiedCell.style.maxWidth = `${width}px`;
        });

        copiedTable.appendChild(copiedHead);
        elements.headerCopy.replaceChildren(copiedTable);

        elements.topScroll.scrollLeft = tableContainer.scrollLeft;
        elements.headerCopy.scrollLeft = tableContainer.scrollLeft;
    }

    let scheduled = false;

    function scheduleInitialization() {
        if (scheduled) {
            return;
        }

        scheduled = true;

        requestAnimationFrame(() => {
            scheduled = false;
            initializeTables();
        });
    }

    document.addEventListener('DOMContentLoaded', scheduleInitialization);
    document.addEventListener('livewire:navigated', scheduleInitialization);

    new MutationObserver(scheduleInitialization).observe(document.body, {
        childList: true,
        subtree: true,
    });
})();