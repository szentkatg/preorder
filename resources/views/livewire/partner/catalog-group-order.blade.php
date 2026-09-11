
<div class="space-y-8 pl-4">
    <style>
        .matrix-scroll::-webkit-scrollbar {
            height: 16px;
        }

        .matrix-scroll::-webkit-scrollbar-track {
            background: #ddd;
        }

        .matrix-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 8px;
        }
    </style> 

    <script>
        function parseOrderNumber(value) {
            if (value === null || value === undefined) {
                return 0;
            }

            return parseFloat(
                value
                    .toString()
                    .replace(/\s/g, '')
                    .replace(',', '.')
                    .replace(/[^0-9.\-]/g, '')
            ) || 0;
        }

        function formatOrderInteger(value) {
            return new Intl.NumberFormat('hu-HU', {
                maximumFractionDigits: 0,
            }).format(value || 0);
        }

        function formatOrderMoney(value) {
            return new Intl.NumberFormat('hu-HU', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(value || 0);
        }

    function pasteSizeQuantities(event, input) {
        event.preventDefault();
    
        const text = event.clipboardData.getData('text');
    
        const clipboardRows = text
            .trim()
            .split(/\r?\n/)
            .map(row => row
                .split(/\t/)
                .map(value => value.replace(',', '.').trim())
            );
    
        const startRow = input.dataset.pasteRow;
    
        const allRows = Array.from(
            new Set(
                Array.from(document.querySelectorAll('input[data-paste-row]'))
                    .map(input => input.dataset.pasteRow)
            )
        );
    
        const startRowIndex = allRows.indexOf(startRow);
    
        if (startRowIndex === -1) {
            return;
        }
    
        const startRowInputs = Array.from(
            document.querySelectorAll('input[data-paste-row="' + startRow + '"]')
        );
    
        const startColIndex = startRowInputs.indexOf(input);
    
        if (startColIndex === -1) {
            return;
        }
    
        const affectedRows = new Set();
    
        clipboardRows.forEach((clipboardRow, rowOffset) => {
            const targetRow = allRows[startRowIndex + rowOffset];
    
            if (!targetRow) {
                return;
            }
    
            const targetInputs = Array.from(
                document.querySelectorAll('input[data-paste-row="' + targetRow + '"]')
            );
    
            clipboardRow.forEach((value, colOffset) => {
                const target = targetInputs[startColIndex + colOffset];
    
                if (!target) {
                    return;
                }
    
                if (value === '') {
                    return;
                }
    
            target.value = value;
            
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
            target.dispatchEvent(new Event('blur'));
            
            affectedRows.add(target);
            });
        });
    
        affectedRows.forEach(target => {
            recalculateOrderRow(target);
        });
    }
    
        function scrollToModelCode(modelCode) {
            if (!modelCode) {
                return;
            }
    
            setTimeout(function () {
                const element = document.querySelector('[data-model-code="' + modelCode + '"]');
    
                if (!element) {
                    return;
                }
    
                document.querySelectorAll('[data-model-code]').forEach(function (row) {
                    row.classList.remove('outline', 'outline-4', 'outline-yellow-400');
                });
    
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });
    
                element.classList.add('outline', 'outline-4', 'outline-yellow-400');
            }, 500);
        }

        function recalculateOrderRow(input) {
            const row = input.closest('[data-order-row]');

            if (!row) {
                return;
            }

            const price = parseOrderNumber(row.dataset.price);
            const retailPrice = parseOrderNumber(row.dataset.retailPrice);

            row.querySelectorAll('[data-size-total]').forEach(function (cell) {
                const sizeId = cell.dataset.sizeId;
                let sizeTotal = 0;

                row.querySelectorAll('[data-piece-input][data-size-id="' + sizeId + '"]').forEach(function (pieceInput) {
                    sizeTotal += parseInt(pieceInput.value || '0', 10) || 0;
                });

                row.querySelectorAll('[data-assortment-input]').forEach(function (assortmentInput) {
                    const assortmentQty = parseInt(assortmentInput.value || '0', 10) || 0;
                    let contentBySize = {};

                    try {
                        contentBySize = JSON.parse(assortmentInput.dataset.contentBySize || '{}');
                    } catch (error) {
                        contentBySize = {};
                    }

                    sizeTotal += assortmentQty * (parseInt(contentBySize[sizeId] || '0', 10) || 0);
                });

                cell.textContent = sizeTotal;
            });

            let rowTotal = 0;
            const sizeTotalCells = row.querySelectorAll('[data-size-total]');

            if (sizeTotalCells.length) {
                sizeTotalCells.forEach(function (cell) {
                    rowTotal += parseInt(cell.textContent || '0', 10) || 0;
                });
            } else {
                row.querySelectorAll('[data-piece-input]').forEach(function (pieceInput) {
                    rowTotal += parseInt(pieceInput.value || '0', 10) || 0;
                });
            }

            const wholesaleValue = rowTotal * price;
            const retailValue = rowTotal * retailPrice;

            const rowTotalCell = row.querySelector('[data-row-total]');
            const rowWholesaleCell = row.querySelector('[data-row-wholesale-value]');
            const rowRetailCell = row.querySelector('[data-row-retail-value]');

            if (rowTotalCell) {
                rowTotalCell.textContent = formatOrderInteger(rowTotal);
            }

            if (rowWholesaleCell) {
                rowWholesaleCell.textContent = formatOrderMoney(wholesaleValue) + ' ' + (rowWholesaleCell.dataset.currency || '');
            }

            if (rowRetailCell) {
                rowRetailCell.textContent = formatOrderMoney(retailValue) + ' ' + (rowRetailCell.dataset.currency || '');
            }

            recalculateOrderSummaries();
        }

        function recalculateOrderSummaries() {
            let currentQuantity = 0;
            let currentWholesaleValue = 0;
            let currentRetailValue = 0;

            document.querySelectorAll('[data-order-row]').forEach(function (row) {
                currentQuantity += parseOrderNumber(row.querySelector('[data-row-total]')?.textContent || '0');
                currentWholesaleValue += parseOrderNumber(row.querySelector('[data-row-wholesale-value]')?.textContent || '0');
                currentRetailValue += parseOrderNumber(row.querySelector('[data-row-retail-value]')?.textContent || '0');
            });

            const currentSummary = document.querySelector('[data-current-group-summary]');
            const fullSummary = document.querySelector('[data-full-order-summary]');

            if (currentSummary) {
                currentSummary.querySelector('[data-summary-quantity]').textContent = formatOrderInteger(currentQuantity);
                currentSummary.querySelector('[data-summary-wholesale-value]').textContent = formatOrderMoney(currentWholesaleValue);
                currentSummary.querySelector('[data-summary-retail-value]').textContent = formatOrderMoney(currentRetailValue);
            }

            if (fullSummary && currentSummary) {
                const initialCurrentQuantity = parseOrderNumber(currentSummary.dataset.initialQuantity);
                const initialCurrentWholesaleValue = parseOrderNumber(currentSummary.dataset.initialWholesaleValue);
                const initialCurrentRetailValue = parseOrderNumber(currentSummary.dataset.initialRetailValue);

                const initialFullQuantity = parseOrderNumber(fullSummary.dataset.initialQuantity);
                const initialFullWholesaleValue = parseOrderNumber(fullSummary.dataset.initialWholesaleValue);
                const initialFullRetailValue = parseOrderNumber(fullSummary.dataset.initialRetailValue);

                fullSummary.querySelector('[data-summary-quantity]').textContent = formatOrderInteger(
                    initialFullQuantity + (currentQuantity - initialCurrentQuantity)
                );

                fullSummary.querySelector('[data-summary-wholesale-value]').textContent = formatOrderMoney(
                    initialFullWholesaleValue + (currentWholesaleValue - initialCurrentWholesaleValue)
                );

                fullSummary.querySelector('[data-summary-retail-value]').textContent = formatOrderMoney(
                    initialFullRetailValue + (currentRetailValue - initialCurrentRetailValue)
                );
            }
        }
        
        function navigateQuantityInputs(event, input) {
            const key = event.key;
        
            if (event.shiftKey && (key === 'ArrowUp' || key === 'ArrowDown')) {
                event.preventDefault();
        
                let value = parseInt(input.value || '0', 10) || 0;
        
                if (key === 'ArrowUp') {
                    value++;
                }
        
                if (key === 'ArrowDown') {
                    value = Math.max(0, value - 1);
                }
        
                input.value = value;
        
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
        
                recalculateOrderRow(input);
        
                return;
            }
        
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(key)) {
                return;
            }
        
            event.preventDefault();
        
            const row = input.dataset.navRow;
            const col = parseInt(input.dataset.navCol, 10);
        
            let targetRow = row;
            let targetCol = col;
        
            if (key === 'ArrowLeft') {
                targetCol--;
            }
        
            if (key === 'ArrowRight') {
                targetCol++;
            }
        
            if (key === 'ArrowUp') {
                targetRow = getPreviousQuantityRow(row);
            }
        
            if (key === 'ArrowDown') {
                targetRow = getNextQuantityRow(row);
            }
        
            if (!targetRow || targetCol < 0) {
                return;
            }
        
            const target = document.querySelector(
                '[data-nav-row="' + targetRow + '"][data-nav-col="' + targetCol + '"]'
            );
        
            if (!target) {
                return;
            }
        
            target.focus();
            target.select();
        }
        
        function getQuantityRows() {
            return Array.from(
                new Set(
                    Array.from(document.querySelectorAll('[data-nav-row]'))
                        .map(element => element.dataset.navRow)
                )
            );
        }
        
        function getPreviousQuantityRow(currentRow) {
            const rows = getQuantityRows();
            const index = rows.indexOf(currentRow);
        
            return index > 0 ? rows[index - 1] : null;
        }
        
        function getNextQuantityRow(currentRow) {
            const rows = getQuantityRows();
            const index = rows.indexOf(currentRow);
        
            return index >= 0 && index < rows.length - 1
                ? rows[index + 1]
                : null;
        }
        
    </script>
    
    @if($this->scrollToModelCode)
        <script>
            scrollToModelCode(@js($this->scrollToModelCode));
        </script>
    @endif


    <div class="sticky top-0 z-40 bg-white pb-4 shadow-sm">    
        @if (session('success'))
            <div class="rounded bg-green-100 p-3 text-green-800">
                {{ session('success') }}
            </div>
        @endif
    
    <div class="sticky top-0 z-40 flex items-center justify-between gap-6 border-b bg-white py-2 text-sm">
        <div class="flex items-center gap-6">
            @if($previousCatalogGroup)
                <button
                    type="button"
                    wire:click="navigateToCatalogGroup(@js($previousCatalogGroup))"
                    class="text-blue-600 hover:underline"
                >
                    ← {{ $previousCatalogGroup }}
                </button>
            @endif
    
            <button
                type="button"
                wire:click="backToCatalogGroups"
                class="font-semibold text-blue-600 hover:underline"
            >
                {{ __('partner.catalog_groups') }}
            </button>
    
            @if($nextCatalogGroup)
                <button
                    type="button"
                    wire:click="navigateToCatalogGroup(@js($nextCatalogGroup))"
                    class="text-blue-600 hover:underline"
                >
                    {{ $nextCatalogGroup }} →
                </button>
            @endif
        </div>
    
        <div class="flex items-center gap-2">
            <input
                type="text"
                wire:model.live.debounce.300ms="modelSearch"
                wire:keydown.enter="jumpToFirstModelSearchResult"
                placeholder="{{ __('partner.search_model_placeholder') }}"
                class="w-64 rounded border-gray-300 text-sm"
            >
    
            <button
                type="button"
                wire:click="jumpToNextModelSearchResult"
                class="rounded border px-3 py-2 hover:bg-gray-100"
                title="{{ __('partner.search_model') }}"
            >
                🔍
            </button>
    

        </div>
    </div>
    
        <div class="mb-4 flex items-start justify-between gap-4 rounded-lg border bg-white p-4 text-sm shadow-sm">
            <div>
                <div class="font-semibold">
                    {{ $order->partner?->erp_partner_code ?? '' }}
                    -
                    {{ $order->partner?->name ?? '' }}
                </div>
            
                <div class="mt-1">
                    {{ $order->partnerAddress?->name ?? $order->partnerAddress?->addrid }}
                </div>
        
                <div class="mt-1">
                    {{ $this->formatAddress($order->partnerAddress) }}
                 </div>
                 <div class="mt-1">
                    <span class="text-gray-500">
                        ({{ __('partner.address_code') }}: {{ $order->partnerAddress?->addrid }})
                    </span>
                </div>
            </div>

            @if(! $order->isSubmitted())
                @php
                    $summary = $this->getOrderSummary();
                
                    $deleteMessage = __('partner.delete_order_confirm', [
                        'partner' => $order->partner?->name ?? '',
                        'address' => $order->partnerAddress?->name ?? '',
                        'quantity' => number_format($summary['quantity'] ?? 0, 0, ',', ' '),
                        'value' => number_format($summary['wholesale_value'] ?? 0, 2, ',', ' '),
                    ]);
                @endphp
            
                <button
                    type="button"
                    wire:click="deleteOrder"
                    wire:confirm="{{ $deleteMessage }}"
                    class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                >
                    🗑️ {{ __('partner.delete_order') }}
                </button>
            @endif
    
        </div>
        
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">
                {{ $this->showAllCatalogGroups ? __('partner.all_catalog_groups') : $catalogGroupName }}
            </h1>
    
			<button
				type="button"
				wire:click="$toggle('showAllCatalogGroups')"
				wire:loading.attr="disabled"
				class="rounded border border-gray-300 bg-white px-3 py-1 text-sm font-semibold text-gray-700 hover:bg-gray-100"
			>
                {{ $this->showAllCatalogGroups
                    ? __('partner.show_current_catalog_group_only')
                    : __('partner.show_all_catalog_groups') }}
            </button>
        </div>
    
        @if ($this->showAllCatalogGroups)
            <div class="rounded-lg border bg-gray-50 p-3">
                <div class="mb-2 text-sm font-semibold text-gray-700">
                    {{ __('partner.catalog_group_navigation') }}
                </div>
    
                <div class="flex flex-wrap gap-2">
                    @foreach (collect($matrixGroups)->pluck('catalog_group')->filter()->unique()->values() as $catalogGroup)
                        <a
                            href="#catalog-group-{{ \Illuminate\Support\Str::slug($catalogGroup) }}"
                            class="rounded bg-white px-3 py-1 text-sm text-blue-700 ring-1 ring-gray-200 hover:bg-blue-50"
                        >
                            {{ $catalogGroup }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    
        @php
            $groupSummary = $this->getCurrentGroupSummary();
            $orderSummary = $this->getFullOrderSummary();
        @endphp
        
        <div class="grid grid-cols-2 gap-4">
            <div
                class="rounded border bg-blue-50 p-4"
                data-current-group-summary
                data-initial-quantity="{{ $groupSummary['quantity'] }}"
                data-initial-wholesale-value="{{ $groupSummary['wholesale_value'] }}"
                data-initial-retail-value="{{ $groupSummary['retail_value'] }}"
            >
                <div class="font-bold mb-2">
                    {{ __('partner.current_group') }}
                </div>
        
                <div>{{ __('partner.total_quantity') }}: <span data-summary-quantity>{{ number_format($groupSummary['quantity']) }}</span></div>
                <div>{{ __('partner.wholesale_value') }}: <span data-summary-wholesale-value>{{ number_format($groupSummary['wholesale_value'], 2, ',', ' ') }}</span> {{ $this->getCurrencySymbol() }}</div>
                <div>{{ __('partner.retail_value') }}: <span data-summary-retail-value>{{ number_format($groupSummary['retail_value'], 2, ',', ' ') }}</span> {{ $this->getCurrencySymbol() }}</div>
            </div>
        
            <div
                class="rounded border bg-green-50 p-4"
                data-full-order-summary
                data-initial-quantity="{{ $orderSummary['quantity'] }}"
                data-initial-wholesale-value="{{ $orderSummary['wholesale_value'] }}"
                data-initial-retail-value="{{ $orderSummary['retail_value'] }}"
            >
                <div class="font-bold mb-2">
                    {{ __('partner.full_order') }}
                </div>
        
                <div>{{ __('partner.total_quantity') }}: <span data-summary-quantity>{{ number_format($orderSummary['quantity']) }}</span></div>
                <div>{{ __('partner.wholesale_value') }}: <span data-summary-wholesale-value>{{ number_format($orderSummary['wholesale_value'], 2, ',', ' ') }}</span> {{ $this->getCurrencySymbol() }}</div>
                <div>{{ __('partner.retail_value') }}: <span data-summary-retail-value>{{ number_format($orderSummary['retail_value'], 2, ',', ' ') }}</span> {{ $this->getCurrencySymbol() }}</div>
            </div>
        </div>
    
        @php
            $printedCatalogGroupAnchors = [];
        @endphp
    
        @foreach ($matrixGroups as $matrixGroup)
            @php
                $catalogGroupAnchor = $matrixGroup['catalog_group'] ?? $matrixGroup['matrix_group'];
    
                $shouldPrintCatalogGroupAnchor = $this->showAllCatalogGroups
                    && $catalogGroupAnchor
                    && ! in_array($catalogGroupAnchor, $printedCatalogGroupAnchors, true);
    
                if ($shouldPrintCatalogGroupAnchor) {
                    $printedCatalogGroupAnchors[] = $catalogGroupAnchor;
                }
        @endphp

        <div
            @if ($shouldPrintCatalogGroupAnchor)
                id="catalog-group-{{ \Illuminate\Support\Str::slug($catalogGroupAnchor) }}"
            @endif
            class="space-y-3 scroll-mt-4"
        >
            <h2 class="text-xl font-semibold">
                {{ $matrixGroup['matrix_group'] }}
            </h2>

            <div
                class=" matrix-scroll overflow-x-auto"
                style="scrollbar-width: auto; scrollbar-color: #888 #ddd;"
            >
                <table class="border-collapse border text-sm">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="sticky left-0 z-30 border border-gray-400 bg-gray-100 p-3 text-center w-20 min-w-20">
                                {{ __('partner.model') }}
                            </th>

                            <th class="sticky left-20 z-30 border border-gray-400 bg-gray-100 p-3 text-center w-24 min-w-24">
                                {{ __('partner.name') }}
                            </th>

                            <th class="sticky left-44 z-30 border border-gray-400 bg-gray-100 p-3 text-center w-24 min-w-24">
                                {{ __('partner.color') }}
                            </th>

                            <th class="border border-gray-400 bg-gray-100 p-3 text-center w-14 min-w-14">
                                {{ __('partner.page') }}
                            </th>

                            <th class="border border-gray-400 bg-gray-100 p-3 text-center w-25 min-w-25">
                                {{ __('partner.wholesale_price') }}
                            </th>

                            <th class="border border-gray-400 bg-gray-100 p-3 text-center w-25 min-w-25">
                                {{ __('partner.retail_price') }}
                            </th>

                            @foreach ($matrixGroup['sizes'] as $size)
                                <th class="border border-gray-400 p-2 text-center w-12 min-w-12">
                                    {{ $size['code'] }}
                                </th>
                            @endforeach

                            @if ($allowAssortmentOrdering)
                                <th class="border border-gray-400 p-2 text-center w-20 min-w-20 bg-yellow-200 font-bold">
                                    {{ __('partner.assortment_order') }}
                                </th>

                                @foreach ($matrixGroup['sizes'] as $size)
                                    <th class="border border-gray-400 p-2 text-center w-12 min-w-12">
                                        {{ $size['code'] }}
                                    </th>
                                @endforeach
                            @endif 

                            <th class="border border-gray-400 p-2 text-center w-24 min-w-24 bg-gray-200 font-bold">
                                {{ __('partner.total_quantity') }}
                            </th>

                            <th class="border border-gray-400 p-2 text-center w-28 min-w-28 bg-gray-200 font-bold">
                                {{ __('partner.wholesale_value') }}
                            </th>

                            <th class="border border-gray-400 p-2 text-center w-28 min-w-28 bg-gray-200 font-bold">
                                {{ __('partner.retail_value') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($matrixGroup['products'] as $product)
                            @foreach ($product['colors'] as $color)
                                <tr
                                    data-order-row
                                    data-model-code="{{ $product['model_code'] ?? '' }}"
                                    data-price="{{ $product['price'] ?? 0 }}"
                                    data-retail-price="{{ $product['retail_price'] ?? 0 }}"
                                >
                                    <td class="sticky left-0 z-20 border border-gray-400 bg-white p-3 font-semibold w-20 min-w-20">
                                        {{ $product['model_code'] }}
                                    </td>

                                    <td class="sticky left-20 z-20 border border-gray-400 bg-white p-3 font-semibold w-24 min-w-24">
                                        <button
                                            type="button"
                                            wire:click="openProductImages({{ $product['id'] }})"
                                            class="text-blue-700 underline hover:text-blue-900"
                                        >
                                            {{ $product['name'] }}
                                        </button>
                                    </td>

                                    <td class="sticky left-44 z-20 border border-gray-400 bg-white p-3 font-semibold w-24 min-w-24">
                                        {{ $color['name'] }}
                                    </td>

                                    <td class="border border-gray-400 bg-white p-3 text-center w-14 min-w-14">
                                        {{ $product['catalog_page'] ?? '' }}
                                    </td>

                                    <td class="border border-gray-400 bg-white p-3 text-right w-25 min-w-25 whitespace-nowrap">
                                        {{ number_format($product['price'], 2, ',', ' ') }}
                                        {{ $this->getCurrencySymbol() }}
                                    </td>

                                    <td class="border border-gray-400 bg-white p-3 text-right w-25 min-w-25 whitespace-nowrap">
                                        {{ number_format($product['retail_price'], 2, ',', ' ') }}
                                        {{ $this->getCurrencySymbol() }}
                                    </td>

                                    @foreach ($matrixGroup['sizes'] as $size)
                                        @php
                                            $sku = $color['sku_map'][$size['id']] ?? null;
                                        @endphp

                                        <td class="border border-gray-400 p-1 text-center w-12 min-w-12">
                                            @if ($sku)
                                            <input
                                                type="number"
                                                min="0"
                                                max="9999"
                                                @disabled($order->isSubmitted())
                                                class="w-16 rounded border-gray-300 text-right disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                                                value="{{ $pieceQuantities[$sku['id']] ?? '' }}"
                                                wire:blur="savePieceQuantity({{ $sku['id'] }}, $event.target.value)"
                                                wire:change="savePieceQuantity({{ $sku['id'] }}, $event.target.value)"
                                                onblur="recalculateOrderRow(this)"
                                                data-piece-input
                                                data-size-id="{{ $size['id'] }}"
                                                data-paste-row="{{ $product['id'] }}-{{ $color['id'] }}"
                                                onpaste="pasteSizeQuantities(event, this)"
                                                onkeydown="navigateQuantityInputs(event, this)"
                                                data-nav-row="{{ $product['id'] }}-{{ $color['id'] }}"
                                                data-nav-col="{{ $loop->index }}"
                                            >
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>
                                    @endforeach

                                    @if ($allowAssortmentOrdering)
                                        <td class="border border-gray-400 p-1 text-center w-20 min-w-20 bg-yellow-100">
                                            @foreach ($color['assortments'] as $assortment)
                                                <div>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max="9999"
                                                        class="w-12 rounded border border-blue-300 bg-white p-1 text-center font-semibold"
                                                        value="{{ $assortmentQuantities[$assortment['sku_id']] ?? '' }}"
                                                        wire:blur="saveAssortmentQuantity({{ $assortment['sku_id'] }}, $event.target.value)"
                                                        wire:change="saveAssortmentQuantity({{ $assortment['sku_id'] }}, $event.target.value)"
                                                        onblur="recalculateOrderRow(this)"
                                                        data-assortment-input
                                                        data-content-by-size='@json($assortment['content_by_size'] ?? [])' 
                                                        onkeydown="navigateQuantityInputs(event, this)"
                                                        data-nav-row="{{ $product['id'] }}-{{ $color['id'] }}"
                                                        data-nav-col="{{ $loop->index }}"
                                                    >
                                                </div>
                                            @endforeach 
                                        </td>

                                        @foreach ($matrixGroup['sizes'] as $size)
                                            @php
                                                $sku = $color['sku_map'][$size['id']] ?? null;
                                            @endphp

                                            <td
                                                class="border border-gray-400 bg-gray-50 p-1 text-center w-12 min-w-12"
                                                data-size-total
                                                data-size-id="{{ $size['id'] }}"
                                            >
                                                @if ($sku)
                                                    {{ $this->getTotalForSku($sku['id']) }}
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    @endif

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-center font-semibold w-24 min-w-24" data-row-total>
                                        {{ $this->getRowTotal($color) }}
                                    </td>

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-right font-semibold w-28 min-w-28 whitespace-nowrap" data-row-wholesale-value data-currency="{{ $this->getCurrencySymbol() }}">
                                        {{ number_format($this->getRowWholesaleValue($product, $color), 2, ',', ' ') }}
                                        {{ $this->getCurrencySymbol() }}
                                    </td>

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-right font-semibold w-28 min-w-28 whitespace-nowrap" data-row-retail-value data-currency="{{ $this->getCurrencySymbol() }}">
                                        {{ number_format($this->getRowRetailValue($product, $color), 2, ',', ' ') }}
                                        {{ $this->getCurrencySymbol() }}
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach


    <div class="flex items-start gap-3">
        <button
            type="button"
            wire:click="save"
            class="inline-flex items-center rounded-lg bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700"
        >
            {{ __('partner.save') }}
        </button>
        <button
            type="button"
            wire:click="exportExcel"
            class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700"
        >
            {{ __('partner.excel_export') }}
        </button>
    
        <label
            for="excelFiles"
            class="inline-flex cursor-pointer items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600"
        >
            {{ __('partner.select_import_files') }}
        </label>
    
        <input
            id="excelFiles"
            type="file"
            wire:model="excelFiles"
            multiple
            accept=".xlsx,.xls"
            class="hidden"
        />
    
        @if (! empty($excelFiles))
            <div class="flex items-start gap-3">
                <div class="text-sm text-gray-700">
                    @foreach ($excelFiles as $file)
                        <div>{{ $file->getClientOriginalName() }}</div>
                    @endforeach
                </div>
    
                <button
                    type="button"
                    wire:click="importExcelFiles"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-50"
                >
                    {{ __('partner.start_import') }}
                </button>
            </div>
        @endif
    </div>
    
        @if (! empty($importResults))
            <div
                wire:key="import-results-{{ md5(($importResults['message'] ?? '') . count($importResults['files'] ?? [])) }}"
                x-data="{ open: true }"
                x-show="open"
                id="import-results-panel"
                class="mt-4 rounded-lg border p-4
            {{ ($importResults['success'] ?? false)
                ? 'border-green-300 bg-green-50 text-green-800'
                : 'border-red-300 bg-red-50 text-red-800' }}"
        >
            <div class="mb-3 flex items-center justify-between">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="text-lg font-bold text-gray-500 hover:text-gray-700"
                    title="Bezárás"
                >
                    ×
                </button>
            </div>
            <div class="font-semibold">
                {{ $importResults['message'] ?? '' }}
            </div>

            @if (! empty($importResults['files']))
                <div class="mt-4 space-y-3">
                    @foreach ($importResults['files'] as $fileResult)
                        <div class="rounded border bg-white p-3 text-sm text-gray-800">
                            <div class="font-semibold">
                                {{ $fileResult['filename'] ?? '' }}
                            </div>

                            @if (! empty($fileResult['reference_number']))
                                <div>
                                    <strong>{{ __('partner.reference_number') }}:</strong>
                                    {{ $fileResult['reference_number'] }}
                                </div>
                            @endif

                            @if (! empty($fileResult['address_code']))
                                <div>
                                    <strong>Címkód:</strong>
                                    {{ $fileResult['address_code'] }}
                                </div>
                            @endif

                            <div>
                                {{ $fileResult['message'] ?? '' }}
                            </div>

                            @if (! empty($fileResult['stats']))
                                <div class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                                    <div><strong>Módosítva:</strong> {{ $fileResult['stats']['updated'] ?? 0 }}</div>
                                    <div><strong>Törölve:</strong> {{ $fileResult['stats']['deleted'] ?? 0 }}</div>
                                    <div><strong>Változatlan:</strong> {{ $fileResult['stats']['unchanged'] ?? 0 }}</div>
                                    <div><strong>Hibás:</strong> {{ $fileResult['stats']['invalid'] ?? 0 }}</div>
                                    <div><strong>Összes változás:</strong> {{ $fileResult['stats']['changed'] ?? 0 }}</div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
    
@php
    $importErrorRows = [];

    foreach (($importResults['files'] ?? []) as $fileResult) {
        foreach (($fileResult['stats']['warnings'] ?? []) as $warning) {
            $row = is_array($warning) ? $warning : ['message' => $warning];

            $row['_filename'] = $fileResult['filename'] ?? '';
            $row['_address_code'] = $fileResult['address_code'] ?? '';

            $importErrorRows[] = $row;
        }
    }
@endphp

    @if (! empty($importErrorRows))
        <div id="import-errors" class="mt-6 rounded-lg border border-yellow-300 bg-yellow-50 p-4">
            <div class="mb-3 font-semibold text-yellow-900">
                Hibás / kihagyott tételek
            </div>
    
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-yellow-200 text-sm">
                    <thead>
                        <tr class="text-left text-yellow-900">
                            <th class="px-3 py-2">Fájl</th>
                            <th class="px-3 py-2">Címkód</th>
                            <th class="px-3 py-2">Sor</th>
                            <th class="px-3 py-2">SKU</th>
                            <th class="px-3 py-2">Méret</th>
                            <th class="px-3 py-2">Mennyiség</th>
                            <th class="px-3 py-2">Hiba</th>
                            <th class="px-3 py-2">JSON</th>
                        </tr>
                    </thead>
    
                    <tbody class="divide-y divide-yellow-100 bg-white">
                        @foreach ($importErrorRows as $errorRow)
                            <tr>
                                <td class="px-3 py-2">
                                    {{ $errorRow['_filename'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['_address_code'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['row'] ?? $errorRow['row_number'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['sku'] ?? $errorRow['SKU'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['size'] ?? $errorRow['size_name'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['quantity'] ?? $errorRow['qty'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2">
                                    {{ $errorRow['message'] ?? $errorRow['error'] ?? '' }}
                                </td>
    
                                <td class="px-3 py-2 text-xs">
                                    <code>
                                        {{ json_encode($errorRow, JSON_UNESCAPED_UNICODE) }}
                                    </code>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif    

    @if ($showImageModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-6">
                <div class="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-xl font-bold">
                            {{ $imageModalTitle }}
                        </h2>
        
                        <button
                            type="button"
                            wire:click="closeImageModal"
                            class="rounded bg-gray-200 px-3 py-1 text-sm hover:bg-gray-300"
                        >
                            ×
                        </button>
                    </div>
        
                    @if (count($imageModalImages))
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            @foreach ($imageModalImages as $image)
                                <div class="rounded border p-3">
                                    <a href="{{ $image['url'] }}" target="_blank">
                                        <img
                                            src="{{ $image['url'] }}"
                                            alt="{{ $image['color_name'] }}"
                                            class="
                                                mb-2
                                                w-full
                                                object-contain
                                                cursor-zoom-in
                                                hover:opacity-90
                                                {{ ($image['type'] ?? '') === 'catalog' ? 'h-96' : 'h-56' }}
                                            "
                                        >
                                    </a>
                    
                                    <div class="text-center text-sm font-semibold">
                                        {{ $image['color_name'] }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="rounded bg-gray-100 p-4 text-gray-700">
                            Ehhez a modellhez nincs kép feltöltve.
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div
            wire:loading.flex
            wire:target="showAllCatalogGroups"
            class="fixed inset-0 z-[9999] items-center justify-center bg-black/50"
        >
            <div class="rounded-lg bg-white px-8 py-6 shadow-xl">
                <div class="flex items-center gap-4">
                    <svg
                        class="h-6 w-6 animate-spin text-blue-600"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle
                            class="opacity-25"
                            cx="12"
                            cy="12"
                            r="10"
                            stroke="currentColor"
                            stroke-width="4"
                        ></circle>
        
                        <path
                            class="opacity-75"
                            fill="currentColor"
                            d="M4 12a8 8 0 018-8v8H4z"
                        ></path>
                    </svg>
        
                    <div>
                        {{ __('partner.prepare') }}
                    </div>
                </div>
            </div>
        </div>

</div>
