<script>
    (() => {
        if (window.__pricingCostColumnResizingBooted) {
            window.__pricingCostColumnResizingInit?.();

            return;
        }

        window.__pricingCostColumnResizingBooted = true;

        let storageKey = null;
        let serverWidths = {};
        const minimumWidth = 72;
        let mutationObserver = null;
        let initialiseTimer = null;
        let isInitialising = false;
        let currentWidths = null;
        const tableLayouts = new WeakMap();

        function fitState(component) {
            return JSON.stringify(['tableFilters', 'tableSearch', 'tableColumnSearches', 'tableColumns']
                .map(name => component.get(name)));
        }

        function horizontalInsets(element, cell) {
            let width = 0;
            for (let current = element; current; current = current.parentElement) {
                const style = getComputedStyle(current);
                width += ['paddingLeft', 'paddingRight', 'borderLeftWidth', 'borderRightWidth']
                    .reduce((total, property) => total + (parseFloat(style[property]) || 0), 0);
                if (current === cell) break;
            }
            return width;
        }

        function measuredTextWidth(context, texts, element, pseudo = null) {
            const style = getComputedStyle(element, pseudo);
            context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
            const spacing = parseFloat(style.letterSpacing) || 0;
            let widest = 0;
            texts.forEach(text => String(text).split(/\r?\n/).forEach(line => {
                line = line.replace(/\s+/g, ' ').trim();
                if (style.textTransform === 'uppercase') line = line.toUpperCase();
                if (style.textTransform === 'lowercase') line = line.toLowerCase();
                widest = Math.max(widest, context.measureText(line).width + Math.max(0, [...line].length - 1) * spacing);
            }));
            return widest;
        }

        async function fitWidthsForUser(button) {
            const container = document.querySelector('[data-pricing-resizable-columns]');
            const component = livewireComponent();
            if (! container || ! component || button.disabled) return;
            const status = button.closest('[data-pricing-column-width-actions]')?.querySelector('[data-pricing-column-width-status]');
            const requestKey = container.dataset.pricingStorageKey;
            const requestQuery = container.dataset.pricingFitQuery;
            const requestState = fitState(component);
            const context = document.createElement('canvas').getContext('2d');
            if (! context) return;
            button.disabled = true;
            if (status) status.textContent = 'A teljes szűrt lista vizsgálata…';
            try {
                const content = await component.call('pricingColumnFitContent');
                await document.fonts.ready;
                const isCurrent = () => container.isConnected && requestKey === storageKey
                    && requestQuery === container.dataset.pricingFitQuery
                    && requestState === fitState(component);
                if (! isCurrent()) {
                    if (status) status.textContent = 'A lista megváltozott. Indítsd újra az igazítást.';
                    return;
                }
                const widths = { ...loadWidths() };
                container.querySelectorAll('.fi-ta-table').forEach(table => {
                    const headers = Array.from(table.querySelectorAll('thead th'));
                    headers.forEach((header, index) => {
                        // Selection and row-action infrastructure are not data columns.
                        if (! header.classList.contains('fi-ta-header-cell')) return;
                        const key = columnKey(header, index);
                        const data = content[key];
                        const heading = header.querySelector('.fi-ta-header-cell-sort-btn') || header;
                        const label = heading.cloneNode(true);
                        label.querySelectorAll('svg,[data-pricing-column-resizer]').forEach(node => node.remove());
                        let headerWidth = measuredTextWidth(context, [label.textContent], heading)
                            + horizontalInsets(heading, header) + (heading.querySelector('svg') ? 24 : 0);
                        if (header.dataset.pricingAverageMultiplier) {
                            headerWidth = Math.max(headerWidth,
                                measuredTextWidth(context, [header.dataset.pricingAverageMultiplier], header, '::before') + 32);
                        }
                        const cell = table.querySelector('tbody tr')?.cells[index];
                        const sample = cell?.querySelector('.fi-badge') || cell?.querySelector('.fi-ta-text-item')
                            || cell?.querySelector('.fi-ta-text') || cell || header;
                        const texts = data?.texts || [cell?.innerText || ''];
                        const padding = cell ? horizontalInsets(sample, cell) : 24;
                        const bodyWidth = measuredTextWidth(context, texts, sample) + Math.max(24, padding)
                            + (data?.icon ? 24 : 0) + (data?.badge ? 16 : 0);
                        widths[key] = Math.min(2000, Math.ceil(Math.max(minimumColumnWidth(header), headerWidth, bodyWidth) + 8));
                    });
                });
                // Persist once, without a table rerender or re-sorting any edited rows.
                await component.call('savePricingColumnWidths', widths);
                if (! isCurrent()) return;
                saveWidths(widths);
                initialisePricingColumnResizing();
                if (status) status.textContent = 'Oszlopszélességek igazítva és mentve.';
            } catch (error) {
                if (status) status.textContent = 'Az igazítás nem sikerült. Próbáld újra.';
                console.error('Pricing column auto-fit failed', error);
            } finally {
                button.disabled = false;
            }
        }

        function loadWidths() {
            if (currentWidths !== null) {
                return currentWidths;
            }

            try {
                const localWidths = JSON.parse(window.localStorage.getItem(storageKey) || '{}');

                currentWidths = Object.keys(serverWidths || {}).length
                    ? { ...serverWidths }
                    : localWidths;

                return currentWidths;
            } catch (error) {
                currentWidths = { ...(serverWidths || {}) };

                return currentWidths;
            }
        }

        function saveWidths(widths) {
            currentWidths = { ...widths };
            window.localStorage.setItem(storageKey, JSON.stringify(widths));
        }

        function livewireComponent() {
            const root = document
                .querySelector('[data-pricing-resizable-columns]')
                ?.closest('[wire\\:id]');

            if (! root || ! window.Livewire) {
                return null;
            }

            return window.Livewire.find(root.getAttribute('wire:id'));
        }

        function saveWidthsForUser(widths) {
            saveWidths(widths);
            livewireComponent()?.call('savePricingColumnWidths', widths);
        }

        function resetWidthsForUser() {
            currentWidths = {};
            window.localStorage.removeItem(storageKey);
            livewireComponent()?.call('resetPricingColumnWidths');
        }

        function columnKey(header, index) {
            const classKey = Array.from(header.classList)
                .find((className) => className.startsWith('fi-ta-header-cell-'));

            if (classKey) {
                return classKey.replace('fi-ta-header-cell-', '');
            }

            const ariaLabel = header.querySelector('[aria-label]')?.getAttribute('aria-label');

            if (ariaLabel) {
                return ariaLabel
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase();
            }

            const label = header.innerText
                .replace(/\s+/g, ' ')
                .trim()
                .toLowerCase();

            return label || `column-${index}`;
        }

        function applyWidth(table, columnIndex, width, notify = true) {
            const layout = tableLayouts.get(table);
            layout.rows.forEach((cells) => {
                const cell = cells[columnIndex];

                if (! cell) {
                    return;
                }

                cell.style.width = `${width}px`;
                cell.style.minWidth = `${width}px`;
                cell.style.maxWidth = `${width}px`;
                cell.dataset.pricingColumnWidth = `${width}`;
            });

            if (notify) {
                updateTableWidth(table, layout.headers);
                window.dispatchEvent(new Event('resize'));
            }
        }

        function updateTableWidth(table, headers) {
            const totalWidth = headers.reduce(
                (total, header) => total + Number(header.dataset.pricingColumnWidth || 0),
                0,
            );

            // An explicit total and fixed layout prevent intrinsic input widths
            // or spare viewport space from expanding a resized column again.
            table.style.tableLayout = 'fixed';
            table.style.minWidth = '0';
            table.style.width = `${totalWidth}px`;
        }

        function clearWidths(table, forgetWidths = true) {
            table.querySelectorAll('th, td').forEach((cell) => {
                cell.style.width = '';
                cell.style.minWidth = '';
                cell.style.maxWidth = '';

                if (forgetWidths) {
                    delete cell.dataset.pricingColumnWidth;
                }
            });

            table.style.tableLayout = '';
            table.style.width = '';
            table.style.minWidth = '';
        }

        function minimumColumnWidth(header) {
            const key = columnKey(header, 0);

            if (['shipping-cost-percent', 'customs-percent'].includes(key)) {
                return Math.ceil(parseFloat(getComputedStyle(document.documentElement).fontSize) * 6.25);
            }

            return minimumWidth;
        }

        function removeExistingResizers(table) {
            table
                .querySelectorAll('[data-pricing-column-resizer]')
                .forEach((resizer) => resizer.remove());
        }

        function initialiseTable(table, resetWidths = false) {
            const widths = loadWidths();
            const headers = Array.from(table.querySelectorAll('thead th'));
            // Check spans once per row, not once per column on every row.
            // table.rows/cells also exclude nested tables from this table's layout.
            const rows = Array.from(table.rows, (row) => Array.from(row.cells))
                .filter((cells) => ! cells.some((cell) => cell.colSpan > 1));
            tableLayouts.set(table, { headers, rows });

            removeExistingResizers(table);
            clearWidths(table, resetWidths);

            // Measure new columns with the natural layout, before fixing every
            // column. Keep existing widths across Livewire and column reorders.
            const initialWidths = headers.map((header, index) => {
                const key = columnKey(header, index);
                const minWidth = minimumColumnWidth(header);
                const isPercent = ['shipping-cost-percent', 'customs-percent'].includes(key);

                return Math.max(
                    header.classList.contains('fi-ta-header-cell') ? minWidth : 0,
                    Number(widths[key] || header.dataset.pricingColumnWidth)
                        || (isPercent ? minWidth : Math.ceil(header.getBoundingClientRect().width)),
                );
            });

            headers.forEach((header, index) => {
                header.dataset.pricingColumnWidth = `${initialWidths[index]}`;
            });

            headers.forEach((header, index) => {
                const key = columnKey(header, index);

                if (! key) {
                    return;
                }

                applyWidth(table, index, initialWidths[index], false);

                if (! header.classList.contains('fi-ta-header-cell')) {
                    return;
                }

                const resizer = document.createElement('span');
                resizer.dataset.pricingColumnResizer = 'true';
                resizer.dataset.pricingColumnKey = key;
                resizer.setAttribute('aria-hidden', 'true');
                header.append(resizer);

                resizer.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const startX = event.clientX;
                    const startWidth = header.getBoundingClientRect().width;

                    resizer.classList.add('is-resizing');
                    document.body.classList.add('pricing-column-resize-active');
                    resizer.setPointerCapture(event.pointerId);

                    const onMove = (moveEvent) => {
                        const nextWidth = Math.max(
                            minimumColumnWidth(header),
                            Math.round(startWidth + moveEvent.clientX - startX),
                        );

                        widths[key] = nextWidth;
                        applyWidth(table, index, nextWidth);
                        saveWidths(widths);
                    };

                    const onUp = (upEvent) => {
                        if (resizer.hasPointerCapture(upEvent.pointerId)) {
                            resizer.releasePointerCapture(upEvent.pointerId);
                        }

                        resizer.classList.remove('is-resizing');
                        document.body.classList.remove('pricing-column-resize-active');
                        saveWidthsForUser(widths);

                        resizer.removeEventListener('pointermove', onMove);
                        resizer.removeEventListener('pointerup', onUp);
                        resizer.removeEventListener('pointercancel', onUp);
                    };

                    resizer.addEventListener('pointermove', onMove);
                    resizer.addEventListener('pointerup', onUp);
                    resizer.addEventListener('pointercancel', onUp);
                });
            });

            updateTableWidth(table, headers);
            window.dispatchEvent(new Event('resize'));
        }

        function scheduleInitialisePricingColumnResizing(delay = 50) {
            window.clearTimeout(initialiseTimer);
            initialiseTimer = window.setTimeout(initialisePricingColumnResizing, delay);
        }

        function hasTableStructureChange(mutations) {
            const structure = 'table, thead, tbody, tr, th, td';

            return mutations.some((mutation) => [...mutation.addedNodes, ...mutation.removedNodes].some(
                (node) => node.nodeType === 1 && (node.matches(structure) || node.querySelector(structure)),
            ));
        }

        function initialisePricingColumnResizing(resetWidths = false) {
            if (isInitialising) {
                return;
            }

            isInitialising = true;
            window.clearTimeout(initialiseTimer);
            initialiseTimer = null;
            // MutationObserver callbacks run after our synchronous DOM writes.
            // Disconnecting discards our own records even on a very slow table.
            mutationObserver?.disconnect();

            const tableContainer = document.querySelector('[data-pricing-resizable-columns]');

            try {
                if (! tableContainer) {
                    return;
                }

                // Livewire navigation can move between cost and retail workspaces
                // without reloading this script. Each page has its own layout.
                if (storageKey !== tableContainer.dataset.pricingStorageKey) {
                    storageKey = tableContainer.dataset.pricingStorageKey;
                    currentWidths = null;

                    try {
                        serverWidths = JSON.parse(tableContainer.dataset.pricingServerWidths || '{}');
                    } catch (error) {
                        serverWidths = {};
                    }
                }

                document
                    .querySelectorAll('[data-pricing-resizable-columns] .fi-ta-table')
                    .forEach((table) => initialiseTable(table, resetWidths));

                document
                    .querySelectorAll('[data-pricing-column-width-fit]')
                    .forEach((button) => {
                        if (button.dataset.pricingFitReady === 'true') return;
                        button.dataset.pricingFitReady = 'true';
                        button.addEventListener('click', () => fitWidthsForUser(button));
                    });

                document
                    .querySelectorAll('[data-pricing-column-width-reset]')
                    .forEach((button) => {
                        if (button.dataset.pricingResetReady === 'true') {
                            return;
                        }

                        button.dataset.pricingResetReady = 'true';
                        button.addEventListener('click', () => {
                            resetWidthsForUser();

                            initialisePricingColumnResizing(true);
                        });
                    });
            } finally {
                isInitialising = false;
                if (tableContainer) {
                    mutationObserver ??= new MutationObserver((mutations) => {
                        // Text, icons, menus and our resize handles are not table structure.
                        if (hasTableStructureChange(mutations)) {
                            scheduleInitialisePricingColumnResizing(80);
                        }
                    });
                    mutationObserver.observe(tableContainer, {
                        childList: true,
                        subtree: true,
                    });
                }
            }
        }

        window.__pricingCostColumnResizingInit = initialisePricingColumnResizing;

        document.addEventListener('DOMContentLoaded', () => initialisePricingColumnResizing());
        document.addEventListener('livewire:navigated', () => scheduleInitialisePricingColumnResizing(50));
        document.addEventListener('apply-table-column-manager', () => {
            scheduleInitialisePricingColumnResizing(120);
        });
        document.addEventListener('reset-table-column-manager', () => scheduleInitialisePricingColumnResizing(120));

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', () => scheduleInitialisePricingColumnResizing(50));
        }

        initialisePricingColumnResizing();
    })();
</script>
