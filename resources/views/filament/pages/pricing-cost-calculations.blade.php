<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            Ez a munkafelület a bekerülési érték kalkuláció áttekintésére szolgál.
            A jobb felső oszlopkezelővel ki/be kapcsolhatók és átrendezhetők az oszlopok,
            az oszlopszélességek a fejléc jobb szélén húzással állíthatók,
            a soroknál pedig a <span class="font-semibold">Részletek</span> gomb mutatja a teljes számítási hátteret.

            <button
                type="button"
                class="ml-2 inline-flex items-center rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                data-pricing-column-width-reset
            >
                Oszlopszélességek alaphelyzetbe
            </button>
        </div>

        <div data-pricing-resizable-columns>
            {{ $this->table }}
        </div>
    </div>

    <style>
        [data-pricing-resizable-columns] .fi-ta-table {
            table-layout: fixed;
        }

        [data-pricing-resizable-columns] .fi-ta-table th,
        [data-pricing-resizable-columns] .fi-ta-table td {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        [data-pricing-resizable-columns] .fi-ta-table th {
            position: relative;
        }

        [data-pricing-column-resizer] {
            position: absolute;
            top: 0;
            right: -4px;
            z-index: 20;
            width: 8px;
            height: 100%;
            cursor: col-resize;
            touch-action: none;
            user-select: none;
        }

        [data-pricing-column-resizer]::after {
            position: absolute;
            top: 0.65rem;
            right: 3px;
            bottom: 0.65rem;
            width: 2px;
            content: "";
            border-radius: 999px;
            background: transparent;
        }

        [data-pricing-column-resizer]:hover::after,
        [data-pricing-column-resizer].is-resizing::after {
            background: rgb(59 130 246);
        }

        body.pricing-column-resize-active {
            cursor: col-resize;
            user-select: none;
        }
    </style>

    <script>
        (() => {
            if (window.__pricingCostColumnResizingBooted) {
                window.__pricingCostColumnResizingInit?.();

                return;
            }

            window.__pricingCostColumnResizingBooted = true;

            const storageKey = 'pricing-cost-calculation-column-widths-v1';
            const minimumWidth = 72;

            function loadWidths() {
                try {
                    return JSON.parse(window.localStorage.getItem(storageKey) || '{}');
                } catch (error) {
                    return {};
                }
            }

            function saveWidths(widths) {
                window.localStorage.setItem(storageKey, JSON.stringify(widths));
            }

            function columnKey(header, index) {
                const label = header.innerText
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase();

                return label || `column-${index}`;
            }

            function applyWidth(table, columnIndex, width) {
                table.querySelectorAll('tr').forEach((row) => {
                    const cell = row.children[columnIndex];

                    if (! cell) {
                        return;
                    }

                    cell.style.width = `${width}px`;
                    cell.style.minWidth = `${width}px`;
                });

                window.dispatchEvent(new Event('resize'));
            }

            function clearWidths(table) {
                table.querySelectorAll('th, td').forEach((cell) => {
                    cell.style.width = '';
                    cell.style.minWidth = '';
                });

                window.dispatchEvent(new Event('resize'));
            }

            function initialiseTable(table) {
                const widths = loadWidths();
                const headers = Array.from(table.querySelectorAll('thead th'));

                headers.forEach((header, index) => {
                    const key = columnKey(header, index);

                    if (widths[key]) {
                        applyWidth(table, index, widths[key]);
                    }

                    if (header.querySelector('[data-pricing-column-resizer]')) {
                        return;
                    }

                    const resizer = document.createElement('span');
                    resizer.dataset.pricingColumnResizer = 'true';
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
                                minimumWidth,
                                Math.round(startWidth + moveEvent.clientX - startX),
                            );

                            widths[key] = nextWidth;
                            applyWidth(table, index, nextWidth);
                        };

                        const onUp = (upEvent) => {
                            resizer.releasePointerCapture(upEvent.pointerId);
                            resizer.classList.remove('is-resizing');
                            document.body.classList.remove('pricing-column-resize-active');
                            saveWidths(widths);

                            resizer.removeEventListener('pointermove', onMove);
                            resizer.removeEventListener('pointerup', onUp);
                            resizer.removeEventListener('pointercancel', onUp);
                        };

                        resizer.addEventListener('pointermove', onMove);
                        resizer.addEventListener('pointerup', onUp);
                        resizer.addEventListener('pointercancel', onUp);
                    });
                });
            }

            function initialisePricingColumnResizing() {
                document
                    .querySelectorAll('[data-pricing-resizable-columns] .fi-ta-table')
                    .forEach(initialiseTable);

                document
                    .querySelectorAll('[data-pricing-column-width-reset]')
                    .forEach((button) => {
                        if (button.dataset.pricingResetReady === 'true') {
                            return;
                        }

                        button.dataset.pricingResetReady = 'true';
                        button.addEventListener('click', () => {
                            window.localStorage.removeItem(storageKey);

                            document
                                .querySelectorAll('[data-pricing-resizable-columns] .fi-ta-table')
                                .forEach(clearWidths);
                        });
                    });
            }

            window.__pricingCostColumnResizingInit = initialisePricingColumnResizing;

            document.addEventListener('DOMContentLoaded', initialisePricingColumnResizing);
            document.addEventListener('livewire:navigated', () => setTimeout(initialisePricingColumnResizing, 50));

            if (window.Livewire) {
                window.Livewire.hook('morph.updated', () => setTimeout(initialisePricingColumnResizing, 50));
            }

            initialisePricingColumnResizing();
        })();
    </script>
</x-filament-panels::page>
