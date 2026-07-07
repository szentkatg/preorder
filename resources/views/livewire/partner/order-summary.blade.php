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

    @if (session('success'))
        <div class="rounded bg-green-100 p-3 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center gap-6 text-sm">
        <a
            href="{{ route('partner.orders.select') }}"
            class="font-semibold text-blue-600 hover:underline"
        >
            ← {{ __('partner.catalog_groups') }}
        </a>
    </div>

    <div class="mb-4 rounded-lg border bg-white p-4 text-sm shadow-sm">
        <div class="text-xl font-bold">
            {{ __('partner.summary_order_sheet') }}
        </div>
    
        <div class="mt-3 space-y-2 text-sm">
        
            <div>
                <span class="font-semibold">
                    {{ __('partner.partner_code') }}:
                </span>
        
                TOTAL
            </div>
        
            <div>
                <span class="font-semibold">
                    {{ __('partner.partner_name') }}:
                </span>
        
                TOTAL
            </div>
        
            <div>
                <span class="font-semibold">
                    {{ __('partner.season') }}:
                </span>
        
                {{ $season->name }}
            </div>
        
            <div>
                <span class="font-semibold">
                    {{ __('partner.brand') }}:
                </span>
        
                {{ $brand->name }}
            </div>
        
            <div>
                <span class="font-semibold">
                    {{ __('partner.order_sheet_type') }}:
                </span>
        
                {{ app()->getLocale() === 'en'
                    ? ($orderSheetType->name_en ?? $orderSheetType->name_hu)
                    : ($orderSheetType->name_hu ?? $orderSheetType->name_en)
                }}
            </div>
        
        </div>

        <div class="mt-2 text-gray-600">
            {{ __('partner.summary_description') }}
        </div>

        <div class="mt-4">
            <button
                type="button"
                wire:click="exportExcel"
                wire:loading.attr="disabled"
                wire:target="exportExcel"
                class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
            >
                {{ __('partner.excel_export') }}
            </button>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">
            {{ __('partner.all_catalog_groups') }}
        </h1>

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
        $summary = $this->getCurrentGroupSummary();
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded border bg-blue-50 p-4">
            <div class="font-bold mb-2">
                {{ __('partner.total_quantity') }}
            </div>

            <div class="text-2xl font-bold">
                {{ number_format($summary['quantity'], 0, ',', ' ') }}
            </div>
        </div>

        <div class="rounded border bg-green-50 p-4">
            <div class="font-bold mb-2">
                {{ __('partner.wholesale_value') }}
            </div>

            <div class="text-2xl font-bold">
                {{ number_format($summary['wholesale_value'], 2, ',', ' ') }}
                {{ $this->getCurrencySymbol() }}
            </div>
        </div>

        <div class="rounded border bg-yellow-50 p-4">
            <div class="font-bold mb-2">
                {{ __('partner.retail_value') }}
            </div>

            <div class="text-2xl font-bold">
                {{ number_format($summary['retail_value'], 2, ',', ' ') }}
                {{ $this->getCurrencySymbol() }}
            </div>
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
                class="matrix-scroll overflow-x-auto"
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
                                <tr>
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

                                        <td class="border border-gray-400 bg-gray-50 p-1 text-center w-12 min-w-12">
                                            @if ($sku)
                                                {{ (int) ($pieceQuantities[$sku['id']] ?? 0) }}
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>
                                    @endforeach

                                    @if ($allowAssortmentOrdering)
                                        <td class="border border-gray-400 p-1 text-center w-20 min-w-20 bg-yellow-100">
                                            @foreach ($color['assortments'] as $assortment)
                                                <div class="font-semibold">
                                                    {{ (int) ($assortmentQuantities[$assortment['sku_id']] ?? 0) }}
                                                </div>
                                            @endforeach
                                        </td>

                                        @foreach ($matrixGroup['sizes'] as $size)
                                            @php
                                                $sku = $color['sku_map'][$size['id']] ?? null;
                                            @endphp

                                            <td class="border border-gray-400 bg-gray-50 p-1 text-center w-12 min-w-12">
                                                @if ($sku)
                                                    {{ $this->getTotalForSku($sku['id']) }}
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    @endif

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-center font-semibold w-24 min-w-24">
                                        {{ $this->getRowTotal($color) }}
                                    </td>

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-right font-semibold w-28 min-w-28 whitespace-nowrap">
                                        {{ number_format($this->getRowWholesaleValue($product, $color), 2, ',', ' ') }}
                                        {{ $this->getCurrencySymbol() }}
                                    </td>

                                    <td class="border border-gray-400 bg-gray-200 p-1 text-right font-semibold w-28 min-w-28 whitespace-nowrap">
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
                        {{ __('partner.no_images_uploaded') }}
                    </div>
                @endif
            </div>
        </div>
    @endif
    
    <div
        wire:loading.flex
        wire:target="exportExcel"
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
                    {{ __('partner.export_in_progress') }}
                </div>
            </div>
        </div>
    </div>    
    
</div>
