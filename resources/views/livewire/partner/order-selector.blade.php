<div class="mx-auto max-w-screen-2xl p-6">
    <div
        x-data="{ open: true }"
        class="sticky top-0 z-50 -mx-6 bg-gray-100 px-6 pb-2 pt-6"
    >
        <div class="grid grid-cols-1 gap-3 rounded-lg border bg-white p-4 shadow-sm md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center">
            <div class="min-w-0 text-sm">
                @if ($this->selectedAddress)
                    <div class="truncate font-semibold">
                        {{ $this->selectedAddress->partner?->erp_partner_code ?? '' }}
                        -
                        {{ $this->selectedAddress->partner?->name ?? '' }}
                    </div>

                    <div class="mt-1 truncate">
                        {{ $this->selectedAddress->name ?? $this->selectedAddress->addrid }}
                    </div>

                    <div class="truncate text-gray-600">
                        {{ $this->formatAddress($this->selectedAddress) }}
                    </div>
                @endif
            </div>

            <h1 class="text-center text-2xl font-bold">
                {{ __('partner.order_selection') }}
            </h1>

            <div class="flex items-center gap-2 justify-self-start md:justify-self-end">
                <form method="POST" action="{{ route('partner.logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                            class="h-4 w-4"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5A2.25 2.25 0 003.75 5.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15m-6-3h10.5m0 0l-3-3m3 3l-3 3"
                            />
                        </svg>
                        {{ __('partner.logout') }}
                    </button>
                </form>

                <button
                    type="button"
                    @click="open = ! open"
                    :aria-expanded="open"
                    aria-controls="order-selector-controls"
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

        <div id="order-selector-controls" x-show="open">
            <div class="h-2"></div>

            @include('livewire.partner.partials.order-selector-form')
        </div>
    </div>

    <livewire:partner.sales-rep-order-summary />

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
                    @elseif ($this->isSalesRep)
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
