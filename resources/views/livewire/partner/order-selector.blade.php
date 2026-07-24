<div class="mx-auto max-w-screen-2xl p-6">
    <div class="sticky top-0 z-50 -mx-6 bg-gray-100 px-6 pb-2 pt-6">

        {{-- 1. Cím + kijelentkezés --}}
        <div class="rounded-lg border bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">
                    {{ __('partner.order_selection') }}
                </h1>

                <form method="POST" action="{{ route('partner.logout') }}">
                    @csrf

                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg"
                             fill="none"
                             viewBox="0 0 24 24"
                             stroke-width="2"
                             stroke="currentColor"
                             class="h-4 w-4">
                            <path stroke-linecap="round"
                                  stroke-linejoin="round"
                                  d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5A2.25 2.25 0 003.75 5.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15m-6-3h10.5m0 0l-3-3m3 3l-3 3" />
                        </svg>

                        {{ __('partner.logout') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- keskeny háttérsáv --}}
        <div class="h-2"></div>

        {{-- 2. Partner adatok --}}
        @if ($this->selectedOrder)
            <div class="rounded-lg border bg-white p-4 text-sm shadow-sm">
                <div class="font-semibold">
                    {{ $this->selectedOrder->partner?->erp_partner_code ?? '' }}
                    -
                    {{ $this->selectedOrder->partner?->name ?? '' }}
                </div>

                <div class="mt-1">
                    {{ $this->selectedOrder->partnerAddress?->name ?? $this->selectedOrder->partnerAddress?->addrid }}
                </div>

                <div class="text-gray-600">
                    {{ $this->formatAddress($this->selectedOrder->partnerAddress) }}
                </div>
            </div>

            <div class="h-2"></div>
        @endif

        @include('livewire.partner.partials.order-selector-form')

        @if (! $summaryLoaded)
            <div
                wire:init="loadSummary"
                class="mt-2 rounded-lg border bg-white p-4 text-sm text-gray-600 shadow-sm"
            >
                {{ __('partner.loading') }}
            </div>
        @endif

        {{-- 3. Sales rep summary fejléc + gombok + szűrők --}}
        @if($summaryLoaded && $this->accessibleOrders->isNotEmpty())
            <div class="rounded-lg border bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        {{ __('partner.sales_rep_summary') }}
                    </h2>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="openSelectedSummaryOrders"
                            wire:loading.attr="disabled"
                            class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600"
                        >
                            {{ __('partner.open_selected_summary') }}
                        </button>

                        <button
                            type="button"
                            wire:click="exportSalesRepSummary"
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600"
                        >
                            {{ __('partner.excel_export') }}
                        </button>
                            <a
                            href="{{ route('partner.order-coverage', ['locale' => app()->getLocale(),])}}"
                            class="rounded bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600"
                        >
                            {{ __('partner.partner_order_coverage') }}
                        </a>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-6">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="summarySearch"
                        placeholder="{{ __('partner.search') }}"
                        class="rounded border"
                    >

                    <select wire:model.live="summarySeasonId" class="rounded border">
                        <option value="">
                            {{ __('partner.all_seasons') }}
                        </option>

                        @foreach($summarySeasons as $season)
                            <option value="{{ $season['id'] }}">
                                {{ $season['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <select wire:model.live="summaryBrandId" class="rounded border">
                        <option value="">
                            {{ __('partner.all_brands') }}
                        </option>

                        @foreach($summaryBrands as $brand)
                            <option value="{{ $brand['id'] }}">
                                {{ $brand['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <select wire:model.live="summaryOrderSheetTypeId" class="rounded border">
                        <option value="">
                            {{ __('partner.all_order_sheet_types') }}
                        </option>

                        @foreach($summaryOrderSheetTypes as $type)
                            <option value="{{ $type['id'] }}">
                                {{ $type['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <select wire:model.live="summaryCurrency" class="rounded border">
                        <option value="">
                            {{ __('partner.all_currencies') }}
                        </option>

                        @foreach($this->summaryCurrencies as $currency)
                            <option value="{{ $currency }}">
                                {{ $currency }}
                            </option>
                        @endforeach
                    </select>

                    <select wire:model.live="summaryFilledFilter" class="rounded border">
                        <option value="">
                            {{ __('partner.all_orders') }}
                        </option>

                        <option value="filled">
                            {{ __('partner.filled_orders') }}
                        </option>

                        <option value="empty">
                            {{ __('partner.empty_orders') }}
                        </option>
                    </select>
                </div>
            </div>
        @endif
    </div>

    @if($summaryLoaded && $this->accessibleOrders->isNotEmpty())
        @error('selectedSummaryOrderIds')
            <div class="mt-4 rounded bg-red-100 p-3 text-sm text-red-800">
                {{ $message }}
            </div>
        @enderror

        {{-- 4. Sales rep summary táblázat ide jön --}}

        <div class="mt-4 rounded-lg border bg-white shadow-sm">
            <div
                id="sales-rep-summary-scroll"
                class="max-h-[45vh] overflow-auto overscroll-contain"
            >
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="sticky top-0 z-30 bg-white shadow-sm">
                        <tr class="text-left text-gray-700">
                            <th class="w-10 bg-white px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    wire:click="{{ count($selectedSummaryOrderIds) === $this->filteredSalesRepOrderSummaries->count() ? 'clearSelectedSummaryOrders' : 'selectAllSummaryOrders' }}"
                                    @checked(count($selectedSummaryOrderIds) === $this->filteredSalesRepOrderSummaries->count() && $this->filteredSalesRepOrderSummaries->count() > 0)
                                >
                            </th>
                            <th class="min-w-[150px] bg-white px-3 py-2">{{ __('partner.partner') }}</th>
                            <th class="min-w-[200px] bg-white px-3 py-2">{{ __('partner.address') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.season') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.brand') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.order_sheet') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.status') }}</th>
                            <th class="w-16 bg-white px-3 py-2 text-center">{{ __('partner.currency') }}</th>
                            <th class="bg-white px-3 py-2 text-right">{{ __('partner.total_quantity') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.total_wholesale') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.total_retail') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.wholesale_value_huf') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.retail_value_huf') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @foreach ($this->filteredSalesRepOrderSummaries as $summary)
                                <tr
                                    wire:key="summary-order-row-{{ $summary['order_id'] }}"
                                    wire:click="openSummaryOrder({{ $summary['order_id'] }})"
                                    class="cursor-pointer hover:bg-gray-50"
                                >
                                <td class="px-3 py-2 text-center" wire:click.stop>
                                  <input
                                    type="checkbox"
                                    value="{{ (string) $summary['order_id'] }}"
                                    wire:model.live="selectedSummaryOrderIds"
                                    wire:key="summary-order-checkbox-{{ $summary['order_id'] }}"
                                    wire:click.stop
                                >
                                </td>

                                <td class="px-3 py-2 font-medium">
                                    {{ trim(($summary['partner_code'] ? $summary['partner_code'] . ' - ' : '') . $summary['partner_name']) }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ collect([$summary['address_name'], $summary['address']])->filter()->implode(' - ') }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $summary['season'] }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $summary['brand'] }}
                                </td>

                                <td class="px-3 py-2">
                                    {{ $summary['type'] }}
                                </td>

                                <td class="px-3 py-2">
                                    <span title="{{ $summary['status_label'] }}">
                                        {{ $summary['status_icon'] }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    {{ $summary['currency'] }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((int) $summary['quantity'], 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $summary['wholesale_value'], 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $summary['retail_value'], 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $summary['wholesale_value_huf'], 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $summary['retail_value_huf'], 0, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                        <tfoot>
                            @foreach ($this->filteredSalesRepOrderSummaries->groupBy('currency') as $currency => $rows)
                                @php
                                    $currencyTotalOrderCount = $rows->count();
                        
                                    $currencyOrdersWithQuantityCount = $rows
                                        ->filter(fn (array $summary): bool => (int) ($summary['quantity'] ?? 0) > 0)
                                        ->count();
                                @endphp
                        
                                <tr class="border-t bg-gray-50 font-semibold">
                                    <td class="px-3 py-2"></td>
                        
                                    <td class="px-3 py-2">
                                        {{ __('partner.total') }} / {{ $currency ?: __('partner.no_currency') }}
                                    </td>
                        
                                    <td class="px-3 py-2">
                                        {{ __('partner.orders') }}
                                    </td>
                        
                                    <td class="px-3 py-2">
                                        {{ $currencyOrdersWithQuantityCount }} {{ __('partner.pcs') }}
                                        /
                                        {{ $currencyTotalOrderCount }} {{ __('partner.pcs') }}
                                    </td>
                        
                                    <td colspan="4" class="px-3 py-2"></td>
                        
                                    <td class="px-3 py-2 text-right">
                                        {{ number_format((int) $rows->sum('quantity'), 0, ',', ' ') }}
                                    </td>
                        
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $rows->sum('wholesale_value'), 0, ',', ' ') }}
                                    </td>
                        
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $rows->sum('retail_value'), 0, ',', ' ') }}
                                    </td>
                        
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $rows->sum('wholesale_value_huf'), 0, ',', ' ') }}
                                    </td>
                        
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $rows->sum('retail_value_huf'), 0, ',', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                        
                            @php
                                $filteredSummaries = $this->filteredSalesRepOrderSummaries;
                        
                                $totalOrderCount = $filteredSummaries->count();
                        
                                $ordersWithQuantityCount = $filteredSummaries
                                    ->filter(fn (array $summary): bool => (int) ($summary['quantity'] ?? 0) > 0)
                                    ->count();
                            @endphp
                        
                            <tr class="border-t-2 bg-gray-100 font-bold">
                                <td class="px-3 py-2"></td>
                        
                                <td class="px-3 py-2">
                                    {{ __('partner.grand_total') }}
                                </td>
                        
                                <td class="px-3 py-2">
                                    {{ __('partner.orders') }}
                                </td>
                        
                                <td class="px-3 py-2">
                                    {{ $ordersWithQuantityCount }} {{ __('partner.pcs') }}
                                    /
                                    {{ $totalOrderCount }} {{ __('partner.pcs') }}
                                </td>
                        
                                <td colspan="4" class="px-3 py-2"></td>
                        
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((int) $this->filteredSalesRepOrderSummaries->sum('quantity'), 0, ',', ' ') }}
                                </td>
                        
                                <td class="whitespace-nowrap px-3 py-2 text-right font-bold">
                                    @if ($this->filteredSalesRepOrderSummaries->pluck('currency')->filter()->unique()->count() <= 1)
                                        {{ number_format((float) $this->filteredSalesRepOrderSummaries->sum('wholesale_value'), 0, ',', ' ') }}
                                        {{ $this->filteredSalesRepOrderSummaries->pluck('currency')->filter()->first() }}
                                    @else
                                        —
                                    @endif
                                </td>
                        
                                <td class="whitespace-nowrap px-3 py-2 text-right font-bold">
                                    @if ($this->filteredSalesRepOrderSummaries->pluck('currency')->filter()->unique()->count() <= 1)
                                        {{ number_format((float) $this->filteredSalesRepOrderSummaries->sum('retail_value'), 0, ',', ' ') }}
                                        {{ $this->filteredSalesRepOrderSummaries->pluck('currency')->filter()->first() }}
                                    @else
                                        —
                                    @endif
                                </td>
                        
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $this->filteredSalesRepOrderSummaries->sum('wholesale_value_huf'), 0, ',', ' ') }}
                                </td>
                        
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $this->filteredSalesRepOrderSummaries->sum('retail_value_huf'), 0, ',', ' ') }}
                                </td>
                            </tr>
                        </tfoot>
                </table>
            </div>
        </div>
    @endif
    @if ($this->selectedOrder)
        <div class="mt-4  rounded-lg border bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ __('partner.catalog_groups') }}
                    </h2>
            
                    <div class="mt-1 text-sm text-gray-600">
                        {{ __('partner.status') }}:
                        <strong>
                            {{ $this->selectedOrder?->isSubmitted()
                                ? __('partner.status_submitted')
                                : __('partner.status_draft') }}
                        </strong>
                    </div>
                </div>
            
                <div class="flex items-center gap-2">
                    @if ($this->selectedOrder && ! $this->selectedOrder->isSubmitted())
                        <button
                            type="button"
                            wire:click="submitSelectedOrder"
                            wire:confirm="{{ __('partner.confirm_submit_order') }}"
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600"
                        >
                            {{ __('partner.submit_order') }}
                        </button>
                    @endif
                
                    @if ($this->selectedOrder && $this->selectedOrder->isSubmitted() && $this->isSalesRep)
                        <button
                            type="button"
                            wire:click="resetSelectedOrderToDraft"
                            wire:confirm="{{ __('partner.confirm_reset_order_to_draft') }}"
                            class="rounded bg-yellow-600 px-4 py-2 text-sm font-semibold text-white hover:bg-yellow-500"
                        >
                            {{ __('partner.reset_order_to_draft') }}
                        </button>
                    @endif
                </div>
            </div>
            @if ($this->catalogGroups->isEmpty())
                <div class="text-sm text-gray-500">
                    {{ __('partner.no_catalog_groups') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-gray-700">
                                <th class="px-3 py-2">{{ __('partner.catalog_group') }}</th>
                                <th class="px-3 py-2 text-right">{{ __('partner.total_quantity') }}</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">{{ __('partner.total_wholesale_value') }}</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">{{ __('partner.total_retail_value') }}</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100">
                            @foreach ($this->catalogGroups as $group)
                                <tr
                                    wire:click="openCatalogGroup(@js($group['name']))"
                                    class="cursor-pointer hover:bg-gray-50"
                                >
                                    <td class="px-3 py-2 font-medium text-gray-900">
                                        {{ $group['name'] }}
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        {{ number_format((int) $group['quantity'], 0, ',', ' ') }}
                                    </td>

                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $group['wholesale_value'], 0, ',', ' ') }}
                                    </td>

                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ number_format((float) $group['retail_value'], 0, ',', ' ') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>

                        <tfoot>
                            <tr class="border-t bg-gray-50 font-semibold">
                                <td class="px-3 py-2">
                                    {{ __('partner.total') }}
                                </td>

                                <td class="px-3 py-2 text-right">
                                    {{ number_format((int) $this->catalogGroups->sum('quantity'), 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $this->catalogGroups->sum('wholesale_value'), 0, ',', ' ') }}
                                </td>

                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    {{ number_format((float) $this->catalogGroups->sum('retail_value'), 0, ',', ' ') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <div
        wire:loading.flex
        wire:target="openSelectedSummaryOrders"
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