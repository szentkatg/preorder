<div x-data="{ open: true }" class="mt-2">
    @if (! $summaryLoaded)
        <div
            wire:init="loadSummary"
            class="rounded-lg border bg-white p-4 text-sm text-gray-600 shadow-sm"
        >
            {{ __('partner.loading') }}
        </div>
    @elseif ($this->accessibleOrders->isNotEmpty())
        <div class="rounded-lg border bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ __('partner.sales_rep_summary') }}
                </h2>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div x-show="open" class="flex flex-wrap gap-2">
                        <button type="button" wire:click="openBulkSubmission" wire:loading.attr="disabled"
                            @disabled(count($selectedSummaryOrderIds) === 0)
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:opacity-50">
                            {{ __('partner.submit_multiple_orders') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openSelectedSummaryOrders"
                            wire:loading.attr="disabled"
                            class="rounded bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-50"
                        >
                            {{ __('partner.open_selected_summary') }}
                        </button>

                        <button
                            type="button"
                            wire:click="exportSalesRepSummary"
                            wire:loading.attr="disabled"
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600 disabled:opacity-50"
                        >
                            {{ __('partner.excel_export') }}
                        </button>

                        <button type="button" wire:click="$dispatch('open-order-import')"
                            class="rounded bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500">
                            {{ __('partner.order_import') }}
                        </button>

                        @if ($this->canExportErp)
                            <button type="button" wire:click="openErpExport" wire:loading.attr="disabled"
                                class="rounded bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:opacity-50">
                                {{ __('partner.erp_export_selected') }}
                            </button>
                        @endif

                        @if ($this->canExportErp)
                            <a
                                href="{{ route('partner.order-coverage', ['locale' => app()->getLocale()]) }}"
                                class="rounded bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600"
                            >
                                {{ __('partner.partner_order_coverage') }}
                            </a>
                        @endif
                    </div>

                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open"
                        aria-controls="sales-rep-summary-content"
                        aria-label="{{ __('partner.toggle_section') }}"
                        title="{{ __('partner.toggle_section') }}"
                        class="rounded border border-gray-300 bg-white p-2 text-gray-600 hover:bg-gray-50 hover:text-gray-900"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                            class="h-5 w-5 transition-transform"
                            :class="{ 'rotate-180': ! open }"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4.5 15.75l7.5-7.5 7.5 7.5"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            <div x-show="open" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-6">
                <select wire:model.live="summarySalesRepCode" aria-label="{{ __('partner.sales_representative') }}" class="rounded border">
                    <option value="">{{ __('partner.all_sales_representatives') }}</option>
                    @foreach ($summarySalesReps as $representative)
                        <option value="{{ $representative['code'] }}">{{ $representative['name'] }}</option>
                    @endforeach
                </select>

                <select wire:model.live="summaryBrandId" class="rounded border">
                    <option value="">{{ __('partner.all_brands') }}</option>
                    @foreach ($summaryBrands as $brand)
                        <option value="{{ $brand['id'] }}">{{ $brand['name'] }}</option>
                    @endforeach
                </select>

                <select wire:model.live="summaryOrderSheetTypeId" class="rounded border">
                    <option value="">{{ __('partner.all_order_sheet_types') }}</option>
                    @foreach ($summaryOrderSheetTypes as $type)
                        <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                    @endforeach
                </select>

                <select wire:model.live="summaryCurrency" class="rounded border">
                    <option value="">{{ __('partner.all_currencies') }}</option>
                    @foreach ($this->summaryCurrencies as $currency)
                        <option value="{{ $currency }}">{{ $currency }}</option>
                    @endforeach
                </select>

                <select wire:model.live="summaryFilledFilter" class="rounded border">
                    <option value="">{{ __('partner.all_orders') }}</option>
                    <option value="filled">{{ __('partner.filled_orders') }}</option>
                    <option value="empty">{{ __('partner.empty_orders') }}</option>
                </select>

                <select wire:model.live="summaryStatus" aria-label="{{ __('partner.order_status') }}" class="rounded border">
                    <option value="">{{ __('partner.all_order_statuses') }}</option>
                    @foreach ($summaryStatuses as $status)
                        <option value="{{ $status['code'] }}">{{ $status['name'] }}</option>
                    @endforeach
                </select>

                <input
                    type="text"
                    wire:model.live.debounce.300ms="summarySearch"
                    placeholder="{{ __('partner.order_summary_search_placeholder') }}"
                    aria-label="{{ __('partner.order_summary_search_placeholder') }}"
                    class="rounded border"
                >
            </div>
        </div>

        <div id="sales-rep-summary-content" x-show="open">
            @error('selectedSummaryOrderIds')
                <div class="mt-4 rounded bg-red-100 p-3 text-sm text-red-800">
                    {{ $message }}
                </div>
            @enderror

            <div class="mt-4 rounded-lg border bg-white shadow-sm">
                <div class="max-h-[45vh] overflow-auto overscroll-contain">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="sticky top-0 z-30 bg-white shadow-sm">
                        <tr class="text-left text-gray-700">
                            <th class="w-10 bg-white px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    wire:click="{{ count($selectedSummaryOrderIds) === $this->filteredSalesRepOrderSummaries->count() ? 'clearSelectedSummaryOrders' : 'selectAllSummaryOrders' }}"
                                    @checked(
                                        count($selectedSummaryOrderIds) > 0
                                        && count($selectedSummaryOrderIds) === $this->filteredSalesRepOrderSummaries->count()
                                    )
                                >
                            </th>
                            <th class="min-w-[150px] bg-white px-3 py-2">{{ __('partner.partner') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.sales_representative') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2">{{ __('partner.address_code') }}</th>
                            <th class="min-w-[200px] bg-white px-3 py-2">{{ __('partner.address') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.season') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.brand') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.order_sheet') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2">{{ __('partner.reference_number') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2">{{ __('partner.order_type') }}</th>
                            <th class="bg-white px-3 py-2">{{ __('partner.status') }}</th>
                            <th class="bg-white px-3 py-2 text-center">{{ __('partner.currency') }}</th>
                            <th class="bg-white px-3 py-2 text-right">{{ __('partner.total_quantity') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.total_wholesale') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.total_retail') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.wholesale_value_huf') }}</th>
                            <th class="whitespace-nowrap bg-white px-3 py-2 text-right">{{ __('partner.retail_value_huf') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($this->filteredSalesRepOrderSummaries as $summary)
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
                                <td class="px-3 py-2" data-order-sales-representative>
                                    {{ $summarySalesRepLabels->get($summary['sales_rep_erp_partner_code'] ?? '') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2" data-order-address-code>{{ $summary['address_code'] ?? '' }}</td>
                                <td class="px-3 py-2">
                                    {{ collect([$summary['address_name'], $summary['address']])->filter()->implode(' - ') }}
                                </td>
                                <td class="px-3 py-2">{{ $summary['season'] }}</td>
                                <td class="px-3 py-2">{{ $summary['brand'] }}</td>
                                <td class="px-3 py-2">{{ $summary['type'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $summary['reference_number'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $summary['order_type'] }}</td>
                                <td class="px-3 py-2">
                                    <span title="{{ $summary['status_label'] }}">{{ $summary['status_icon'] }}</span>
                                </td>
                                <td class="px-3 py-2 text-center">{{ $summary['currency'] }}</td>
                                <td class="px-3 py-2 text-right">
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
                        @empty
                            <tr>
                                <td colspan="17" class="px-3 py-6 text-center text-gray-500">
                                    {{ __('partner.no_orders') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                    <tfoot>
                        @foreach ($this->filteredSalesRepOrderSummaries->groupBy('currency') as $currency => $rows)
                            <tr class="border-t bg-gray-50 font-semibold">
                                <td></td>
                                <td colspan="11" class="px-3 py-2">
                                    {{ __('partner.total') }} – {{ $currency }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((int) $rows->sum('quantity'), 0, ',', ' ') }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((float) $rows->sum('wholesale_value'), 0, ',', ' ') }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((float) $rows->sum('retail_value'), 0, ',', ' ') }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((float) $rows->sum('wholesale_value_huf'), 0, ',', ' ') }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    {{ number_format((float) $rows->sum('retail_value_huf'), 0, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div
        wire:loading.flex
        wire:target="openSelectedSummaryOrders"
        class="fixed inset-0 z-[9999] items-center justify-center bg-black/50"
    >
        <div class="rounded-lg bg-white px-8 py-6 shadow-xl">
            {{ __('partner.prepare') }}
        </div>
    </div>

    @if ($showErpExportModal && $this->canExportErp)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-black/50 p-4"
            wire:key="erp-export-modal" x-on:keydown.escape.window="$wire.closeErpExport()">
            <section role="dialog" aria-modal="true" aria-labelledby="erp-export-heading"
                class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
                <h2 id="erp-export-heading" class="text-lg font-semibold">{{ __('partner.erp_export_selected') }}</h2>
                <p class="mt-2 text-sm text-gray-600">{{ __('partner.erp_export_description', ['count' => count($erpExportOrderIds)]) }}</p>
                <form wire:submit="exportErpOrders" class="mt-4 space-y-4">
                    <div>
                        <label for="erp-export-note" class="block text-sm font-medium">{{ __('partner.erp_export_note') }}</label>
                        <textarea id="erp-export-note" wire:model="erpExportNote" maxlength="250" rows="3"
                            class="mt-1 w-full rounded border"></textarea>
                        @error('erpExportNote') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="erp-export-delivery" class="block text-sm font-medium">{{ __('partner.erp_export_delivery') }}</label>
                        <input id="erp-export-delivery" type="date" wire:model="erpExportDeliveryDate" required class="mt-1 w-full rounded border">
                        @error('erpExportDeliveryDate') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    @error('erpExport') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                    @error('selectedSummaryOrderIds') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeErpExport" class="rounded border px-4 py-2">{{ __('partner.erp_export_cancel') }}</button>
                        <button type="submit" wire:loading.attr="disabled" class="rounded bg-green-700 px-4 py-2 font-semibold text-white disabled:opacity-50">
                            {{ __('partner.erp_export_download') }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
