<x-filament-panels::page>
    <div class="space-y-4">
        @if (method_exists($this, 'selectWholesaleCurrency'))
            <div class="pricing-project-selection" role="group" aria-label="Nagyker árak pénzneme">
                @foreach (\App\Services\Pricing\WholesaleCalculator::CURRENCIES as $currencyCode)
                    <button type="button" wire:click="selectWholesaleCurrency('{{ $currencyCode }}')" wire:loading.attr="disabled"
                        aria-pressed="{{ $this->retailCurrencyCode() === $currencyCode ? 'true' : 'false' }}"
                        style="padding:.375rem .75rem;border:1px solid currentColor;border-radius:.5rem;{{ $this->retailCurrencyCode() === $currencyCode ? 'font-weight:700;text-decoration:underline' : '' }}">
                        {{ $currencyCode }}
                    </button>
                @endforeach
            </div>
        @endif
        @if (method_exists($this, 'selectRetailCurrency'))
            <div class="pricing-project-selection" role="group" aria-label="Kisker árak pénzneme">
                @foreach (\App\Services\Pricing\ForeignRetailCalculator::CURRENCIES as $currencyCode)
                    <button type="button" wire:click="selectRetailCurrency('{{ $currencyCode }}')" wire:loading.attr="disabled"
                        aria-pressed="{{ $this->retailCurrencyCode() === $currencyCode ? 'true' : 'false' }}"
                        style="padding: .375rem .75rem; border: 1px solid currentColor; border-radius: .5rem; {{ $this->retailCurrencyCode() === $currencyCode ? 'font-weight:700; text-decoration:underline' : '' }}">
                        {{ $currencyCode }}
                    </button>
                @endforeach
            </div>
        @endif
        <div class="pricing-project-selection">
            <label>
                <span>Árprojekt</span>
                <select wire:change="selectPricingProject($event.target.value)" wire:loading.attr="disabled">
                    <option value="" @selected($this->pricingProjectId === null)>Válassz árprojektet…</option>
                    @foreach ($this->pricingProjectOptions as $projectOption)
                        <option value="{{ $projectOption->id }}" @selected($this->pricingProjectId === (int) $projectOption->id)>
                            {{ $projectOption->name }} — {{ $projectOption->season?->name }}
                            ({{ $projectOption->status === 'closed' ? 'Lezárt' : ($projectOption->status === 'draft' ? 'Tervezet' : 'Aktív') }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="pricing-archive-toggle">
                <input type="checkbox" @checked($this->pricingArchive)
                    wire:change="setPricingArchive($event.target.checked)" wire:loading.attr="disabled">
                Archív megtekintés
            </label>
            @if ($this->pricingArchive)
                <span>Lezárt árprojektek — csak olvasható, exportálható nézet.</span>
            @elseif (! $this->pricingSelectedProject)
                <span>Válassz egy nem lezárt árprojektet. Kiválasztás nélkül nem töltünk be tételeket.</span>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            {{ $this->pricingWorkspaceDescription() }}
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

        @if (method_exists($this, 'wholesaleVatPercent'))
            <div class="pricing-project-selection" data-wholesale-vat-panel>
                @if ($this->retailCurrencyCode() === 'EUR')
                    <label>
                        <span>EUR kisker ár nettósítása — ÁFA %</span>
                        <input type="text" inputmode="decimal" value="{{ $this->eurVatPercent }}" placeholder="ÁFA % megadása"
                            wire:change="setEurVatPercent($event.target.value)" wire:loading.attr="disabled"
                            aria-label="EUR kisker ár ÁFA százalék" style="width:9rem;border:1px solid currentColor;border-radius:.375rem;padding:.25rem .5rem;background:transparent">
                    </label>
                    @error('eurVatPercent') <span role="alert">{{ $message }}</span> @enderror
                    @if (method_exists($this, 'selectDistributorPriceList'))
                    <label data-distributor-price-list-panel>
                        <span>Disztribútori árlista</span>
                        <select wire:change="selectDistributorPriceList($event.target.value)" wire:loading.attr="disabled">
                            <option value="" @selected($this->distributorPriceListId === null)>Válassz disztribútori árlistát…</option>
                            @foreach ($this->distributorPriceLists as $distributorList)
                                <option value="{{ $distributorList->id }}" @selected($this->distributorPriceListId === (int) $distributorList->id)>
                                    {{ $distributorList->code }} ← {{ $distributorList->sourceWholesalePriceList?->code }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    @if ($this->selectedDistributorPriceList)
                        <span>Forrás: {{ $this->selectedDistributorPriceList->sourceWholesalePriceList?->code }} — csak ennek a nagyker árlistának a sorai. ÁFA csak az összehasonlításhoz.</span>
                    @else
                        <span>Válassz disztribútori árlistát, vagy hozz létre újat az Árlisták törzsben. ÁFA csak az összehasonlításhoz; felhasználónként megjegyezzük.</span>
                    @endif
                    @else
                        <span>Az ÁFA csak az összehasonlításhoz szolgál; felhasználónként megjegyezzük.</span>
                    @endif
                @else
                    <span>HUF kisker ár nettósítása: 27% ÁFA — csak a nettó/nettó szorzóhoz.</span>
                @endif
            </div>
        @endif

        @php($pendingAdjustment = $this->pendingParameterAdjustment ?? null)
        @php($selectedAffectedRows = $pendingAdjustment['scope_counts'][$this->pendingParameterScope ?? ''] ?? null)

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

        <div
            data-pricing-resizable-columns
            data-pricing-storage-key="{{ $this->pricingColumnStorageKey() }}"
            data-pricing-server-widths="{{ json_encode($this->pricingColumnWidths) }}"
        >
            {{ $this->table }}
        </div>
    </div>

    <style>
        .pricing-project-selection {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid rgb(209 213 219);
            border-radius: 0.75rem;
            font-size: 0.875rem;
        }

        .pricing-project-selection label {
            display: flex;
            min-width: 0;
            max-width: 100%;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .pricing-project-selection select {
            max-width: 100%;
            min-width: min(18rem, 100%);
            border: 1px solid rgb(156 163 175);
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            background-color: white;
            color: rgb(17 24 39);
        }

        .dark .pricing-project-selection {
            border-color: rgb(55 65 81);
        }

        .dark .pricing-project-selection select {
            background-color: rgb(17 24 39);
            color: rgb(229 231 235);
        }

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
            padding-block: 0.25rem;
            line-height: 1.25rem;
            white-space: normal;
            overflow-wrap: anywhere;
            text-overflow: clip;
            vertical-align: bottom;
        }

        /* Sortable headings contain a text node and icons. Inline flow lets the
           label wrap inside a fixed-width column without enlarging the column. */
        [data-pricing-resizable-columns] .fi-ta-header-cell-sort-btn {
            display: block;
            width: 100%;
            min-width: 0;
            white-space: normal;
            overflow-wrap: anywhere;
            text-align: inherit;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-tooltip {
            white-space: normal;
            overflow-wrap: anywhere;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-sort-btn > svg {
            display: inline-block;
            vertical-align: middle;
        }

        [data-pricing-resizable-columns] th[data-pricing-average-multiplier]::before {
            content: attr(data-pricing-average-multiplier);
            display: block;
            width: fit-content;
            max-width: 100%;
            margin-bottom: 0.125rem;
            margin-inline-start: auto;
            padding: 0.125rem 0.375rem;
            border: 1px solid rgb(147 197 253);
            border-radius: 0.375rem;
            background-color: rgb(239 246 255);
            color: rgb(30 64 175);
            font-size: 0.8125rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.125rem;
        }

        .dark [data-pricing-resizable-columns] th[data-pricing-average-multiplier]::before {
            border-color: rgb(59 130 246);
            background-color: rgb(30 58 138 / 0.35);
            color: rgb(191 219 254);
        }

        [data-pricing-resizable-columns] .fi-ta-table > tbody > tr > .fi-ta-cell {
            padding-block: 0.125rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-icon {
            padding-block: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input-wrp,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn {
            min-height: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input {
            height: 1.5rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-icon .fi-icon {
            width: 1.25rem;
            height: 1.25rem;
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-input {
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-down {
            color: rgb(185 28 28);
        }

        [data-pricing-resizable-columns] .pricing-retail-manual-up {
            color: rgb(21 128 61);
        }

        .dark [data-pricing-resizable-columns] .pricing-retail-manual-down {
            color: rgb(248 113 113);
        }

        .dark [data-pricing-resizable-columns] .pricing-retail-manual-up {
            color: rgb(74 222 128);
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-badge {
            padding-block: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell .fi-ta-text-item,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-input,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-link,
        [data-pricing-resizable-columns] .fi-ta-cell .fi-btn {
            line-height: 1.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-ta-text-input,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-ta-text-input {
            min-width: 0;
            padding-inline: 0.5rem;
        }

        [data-pricing-resizable-columns] .fi-ta-header-cell-shipping-cost-percent,
        [data-pricing-resizable-columns] .fi-ta-header-cell-customs-percent,
        [data-pricing-resizable-columns] .fi-ta-individual-search-cell-shipping-cost-percent,
        [data-pricing-resizable-columns] .fi-ta-individual-search-cell-customs-percent {
            min-width: 0;
            padding-inline: 0.5rem;
        }

        [data-pricing-resizable-columns] [data-pricing-column-width].fi-ta-individual-search-cell,
        [data-pricing-resizable-columns] [data-pricing-column-width] .fi-ta-search-field,
        [data-pricing-resizable-columns] [data-pricing-column-width] .fi-ta-text-input {
            min-width: 0;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-input-wrp,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-input-wrp {
            width: 5.25rem;
            min-width: 5.25rem;
        }

        [data-pricing-resizable-columns] .fi-ta-cell-shipping-cost-percent .fi-input-wrp-suffix,
        [data-pricing-resizable-columns] .fi-ta-cell-customs-percent .fi-input-wrp-suffix {
            flex: 0 0 auto;
            padding-inline: 0.25rem 0.5rem;
        }

        [data-pricing-resizable-columns] .pricing-percent-input {
            min-width: 0;
            padding-inline: 0.5rem 0.25rem;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        [data-pricing-resizable-columns] .pricing-percent-input::-webkit-outer-spin-button,
        [data-pricing-resizable-columns] .pricing-percent-input::-webkit-inner-spin-button {
            margin: 0;
            appearance: none;
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

            let storageKey = null;
            let serverWidths = {};
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

            function applyWidth(table, columnIndex, width, notify = true) {
                table.querySelectorAll('tr').forEach((row) => {
                    // Group headings and empty-state rows span several columns.
                    if (Array.from(row.children).some((cell) => cell.colSpan > 1)) {
                        return;
                    }

                    const cell = row.children[columnIndex];

                    if (! cell) {
                        return;
                    }

                    cell.style.width = `${width}px`;
                    cell.style.minWidth = `${width}px`;
                    cell.style.maxWidth = `${width}px`;
                    cell.dataset.pricingColumnWidth = `${width}`;
                });

                const headers = Array.from(table.querySelectorAll('thead th'));
                const totalWidth = headers.reduce(
                    (total, header) => total + Number(header.dataset.pricingColumnWidth || 0),
                    0,
                );

                // An explicit total and fixed layout prevent intrinsic input widths
                // or spare viewport space from expanding a resized column again.
                table.style.tableLayout = 'fixed';
                table.style.minWidth = '0';
                table.style.width = `${totalWidth}px`;
                if (notify) {
                    window.dispatchEvent(new Event('resize'));
                }
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

            function initialiseTable(table) {
                const widths = loadWidths();
                const headers = Array.from(table.querySelectorAll('thead th'));

                removeExistingResizers(table);
                clearWidths(table, false);

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

                window.dispatchEvent(new Event('resize'));
            }

            function scheduleInitialisePricingColumnResizing(delay = 50) {
                window.clearTimeout(initialiseTimer);
                initialiseTimer = window.setTimeout(initialisePricingColumnResizing, delay);
            }

            function initialisePricingColumnResizing() {
                suppressMutationObserverUntil = Date.now() + 150;

                const tableContainer = document.querySelector('[data-pricing-resizable-columns]');

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
                                .forEach((table) => {
                                    clearWidths(table);
                                    initialiseTable(table);
                                });
                        });
                    });

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
