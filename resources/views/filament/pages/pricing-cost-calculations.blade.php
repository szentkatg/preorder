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

        @php($pendingAdjustment = $this->pendingParameterAdjustment)
        @php($selectedAffectedRows = $pendingAdjustment['scope_counts'][$this->pendingParameterScope] ?? null)

        @if ($pendingAdjustment)
            <div class="pricing-inline-parameter-panel">
                <div class="pricing-inline-parameter-text">
                    <div class="pricing-inline-parameter-title">
                        Paraméter módosítás megerősítése
                    </div>

                    <div class="pricing-inline-parameter-summary">
                        <span class="pricing-inline-parameter-pill">
                            {{ $pendingAdjustment['parameter_label'] ?? 'Paraméter' }}:
                            {{ number_format((float) ($pendingAdjustment['value'] ?? 0), 2, ',', ' ') }}%
                        </span>

                        @if (filled($pendingAdjustment['model_code'] ?? null))
                            <span>Modell: {{ $pendingAdjustment['model_code'] }}</span>
                        @endif

                        @if (filled($pendingAdjustment['supplier_name'] ?? null))
                            <span>Beszállító: {{ $pendingAdjustment['supplier_name'] }}</span>
                        @endif

                        @if ($selectedAffectedRows !== null)
                            <span class="pricing-inline-parameter-impact">
                                A kiválasztott szűkítés várhatóan {{ number_format((int) $selectedAffectedRows, 0, ',', ' ') }} sort érint.
                            </span>
                        @endif
                    </div>
                </div>

                <div class="pricing-inline-parameter-actions">
                    <label class="pricing-inline-parameter-field">
                        <span>Érvényesítés szintje</span>
                        <select wire:model.live="pendingParameterScope">
                            @foreach (($pendingAdjustment['scope_options'] ?? []) as $scope => $label)
                                <option value="{{ $scope }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button
                        type="button"
                        class="pricing-inline-parameter-save"
                        wire:click="confirmPendingParameterAdjustment"
                        wire:loading.attr="disabled"
                    >
                        Mentés és újraszámítás
                    </button>

                    <button
                        type="button"
                        class="pricing-inline-parameter-cancel"
                        wire:click="cancelPendingParameterAdjustment"
                        wire:loading.attr="disabled"
                    >
                        Mégse
                    </button>
                </div>
            </div>
        @endif

        <div data-pricing-resizable-columns>
            {{ $this->table }}
        </div>
    </div>

    <style>
        [data-pricing-resizable-columns] .fi-ta-table {
            width: max-content;
            min-width: 100%;
            table-layout: auto;
        }

        [data-pricing-resizable-columns] {
            max-width: 100%;
        }

        [data-pricing-resizable-columns] .fi-ta-table th,
        [data-pricing-resizable-columns] .fi-ta-table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        .pricing-inline-parameter-panel {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: end;
            justify-content: space-between;
            padding: 1rem;
            color: rgb(113 63 18);
            background: rgb(255 251 235);
            border: 1px solid rgb(252 211 77);
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }

        .pricing-inline-parameter-text {
            min-width: 18rem;
        }

        .pricing-inline-parameter-title {
            font-weight: 700;
        }

        .pricing-inline-parameter-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            margin-top: 0.35rem;
            font-size: 0.875rem;
        }

        .pricing-inline-parameter-pill {
            font-weight: 700;
        }

        .pricing-inline-parameter-impact {
            font-weight: 700;
            color: rgb(146 64 14);
        }

        .pricing-inline-parameter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: end;
        }

        .pricing-inline-parameter-field {
            display: grid;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .pricing-inline-parameter-field select {
            min-width: 16rem;
            padding: 0.45rem 0.65rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgb(17 24 39);
            background: white;
            border: 1px solid rgb(217 119 6);
            border-radius: 0.5rem;
        }

        .pricing-inline-parameter-save,
        .pricing-inline-parameter-cancel {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.5rem;
        }

        .pricing-inline-parameter-save {
            color: white;
            background: rgb(37 99 235);
        }

        .pricing-inline-parameter-save:disabled,
        .pricing-inline-parameter-cancel:disabled {
            opacity: 0.6;
        }

        .pricing-inline-parameter-cancel {
            color: rgb(55 65 81);
            background: white;
            border: 1px solid rgb(209 213 219);
        }

        .dark .pricing-inline-parameter-panel {
            color: rgb(254 243 199);
            background: rgb(69 26 3 / 0.45);
            border-color: rgb(180 83 9);
        }

        .dark .pricing-inline-parameter-field select,
        .dark .pricing-inline-parameter-cancel {
            color: rgb(229 231 235);
            background: rgb(17 24 39);
            border-color: rgb(75 85 99);
        }

        .dark .pricing-inline-parameter-impact {
            color: rgb(252 211 77);
        }
    </style>

    <script>
        (() => {
            if (window.__pricingCostColumnResizingBooted) {
                window.__pricingCostColumnResizingInit?.();

                return;
            }

            window.__pricingCostColumnResizingBooted = true;

            const storageKey = 'pricing-cost-calculation-column-widths-v2-user-{{ auth()->id() ?? 'guest' }}';
            const serverWidths = @js($this->pricingColumnWidths);
            const minimumWidth = 72;
            let mutationObserver = null;
            let observedTableContainer = null;
            let initialiseTimer = null;
            let suppressMutationObserverUntil = 0;
            let currentWidths = null;

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

            function applyWidth(table, columnIndex, width) {
                table.querySelectorAll('tr').forEach((row) => {
                    const cell = row.children[columnIndex];

                    if (! cell) {
                        return;
                    }

                    cell.style.width = `${width}px`;
                    cell.style.minWidth = `${width}px`;
                    cell.dataset.pricingColumnWidth = `${width}`;
                });

                window.dispatchEvent(new Event('resize'));
            }

            function clearWidths(table) {
                table.querySelectorAll('th, td').forEach((cell) => {
                    cell.style.width = '';
                    cell.style.minWidth = '';
                    delete cell.dataset.pricingColumnWidth;
                });

                window.dispatchEvent(new Event('resize'));
            }

            function removeExistingResizers(table) {
                table
                    .querySelectorAll('[data-pricing-column-resizer]')
                    .forEach((resizer) => resizer.remove());
            }

            function initialiseTable(table) {
                const widths = loadWidths();
                const headers = Array.from(table.querySelectorAll('thead th'));

                removeExistingResizers(table);

                headers.forEach((header, index) => {
                    const key = columnKey(header, index);

                    if (! key) {
                        return;
                    }

                    if (widths[key]) {
                        applyWidth(table, index, widths[key]);
                    }

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
                                minimumWidth,
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
            }

            function scheduleInitialisePricingColumnResizing(delay = 50) {
                window.clearTimeout(initialiseTimer);
                initialiseTimer = window.setTimeout(initialisePricingColumnResizing, delay);
            }

            function closeColumnManagerModalIfOpen() {
                window.setTimeout(() => {
                    const closeButton = Array.from(document.querySelectorAll('.fi-modal-close-btn'))
                        .find((button) => button.getClientRects().length > 0);

                    closeButton?.click();
                }, 100);
            }

            function initialisePricingColumnResizing() {
                suppressMutationObserverUntil = Date.now() + 150;

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
                            resetWidthsForUser();

                            document
                                .querySelectorAll('[data-pricing-resizable-columns] .fi-ta-table')
                                .forEach(clearWidths);
                        });
                    });

                const tableContainer = document.querySelector('[data-pricing-resizable-columns]');

                if (tableContainer && tableContainer !== observedTableContainer) {
                    mutationObserver?.disconnect();
                    observedTableContainer = tableContainer;
                    mutationObserver = new MutationObserver(() => {
                        if (Date.now() < suppressMutationObserverUntil) {
                            return;
                        }

                        scheduleInitialisePricingColumnResizing(80);
                    });
                    mutationObserver.observe(tableContainer, {
                        childList: true,
                        subtree: true,
                    });
                }
            }

            window.__pricingCostColumnResizingInit = initialisePricingColumnResizing;

            document.addEventListener('DOMContentLoaded', initialisePricingColumnResizing);
            document.addEventListener('livewire:navigated', () => scheduleInitialisePricingColumnResizing(50));
            document.addEventListener('apply-table-column-manager', () => {
                closeColumnManagerModalIfOpen();
                scheduleInitialisePricingColumnResizing(120);
            });
            document.addEventListener('reset-table-column-manager', () => scheduleInitialisePricingColumnResizing(120));

            if (window.Livewire) {
                window.Livewire.hook('morph.updated', () => scheduleInitialisePricingColumnResizing(50));
            }

            initialisePricingColumnResizing();
        })();
    </script>
</x-filament-panels::page>
