<div class="w-full min-w-0 space-y-4 p-6" data-order-overview>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('partner.orders.select', ['tab' => 'summary']) }}" wire:navigate class="rounded border bg-white px-4 py-2 font-semibold">{{ __('partner.back_to_orders') }}</a>
            @if ($this->catalogGroups->isNotEmpty())
                <button type="button" wire:click="openOrder" class="rounded border bg-white px-4 py-2">{{ __('partner.open_order') }}</button>
            @endif
        </div>
        <x-partner-language-selector />
    </div>
    <h1 class="text-2xl font-bold">{{ __('partner.aggregated_data') }}</h1>
    @error('orderSubmission') <p role="alert" class="rounded bg-amber-100 p-3 text-amber-900">{{ $message }}</p> @enderror
    <x-partner-season-banner :season="$this->selectedSeason" :brand="$this->selectedOrder->brand" :order-sheet-type="$this->selectedOrder->orderSheetType" />
    <p class="font-semibold">{{ $this->selectedOrder->partner?->name }} — {{ $this->selectedOrder->partnerAddress?->name }} — {{ $this->selectedOrder->reference_number }}</p>
    @if ($this->selectedOrder)
        <div class="mt-4 rounded-lg border bg-white p-6 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ __('partner.catalog_groups') }}
                    </h2>

                    <div class="mt-1 text-sm text-gray-600">
                        {{ __('partner.status') }}:
                        <strong>
                            {{ $this->selectedOrder->isSubmitted()
                                ? __('partner.status_submitted')
                                : __('partner.status_draft') }}
                        </strong>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @if (! $this->selectedOrder->isSubmitted())
                        <button
                            type="button"
                            wire:click="submitSelectedOrder"
                            wire:confirm="{{ __('partner.confirm_submit_order') }}"
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600"
                        >
                            {{ __('partner.submit_order') }}
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
                                <td class="px-3 py-2">{{ __('partner.total') }}</td>
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
</div>
