        <p class="mt-1 text-sm text-red-600">
            {{ __('partner.order_selection_description') }}
        </p>

    <div class="rounded-lg border bg-white p-6 shadow-sm">
        <div class="grid gap-4 md:grid-cols-6">

            {{-- 1. sor --}}
            <div class="md:col-span-2">
                <div class="mb-2">
                    <input
                        type="text"
                        disabled
                        class="w-full rounded border-gray-300 opacity-0"
                        tabindex="-1"
                    >
                </div>

                <label class="mb-1 block text-sm font-medium">
                    {{ __('partner.season') }}
                </label>

                <select wire:model.live="seasonId" class="w-full rounded border-gray-300 bg-yellow-100">
                    <option value="">{{ __('partner.select_season') }}</option>

                    @foreach ($this->seasons as $season)
                        <option value="{{ $season['id'] }}">
                            {{ $season['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-4">
                <label class="mb-1 block text-sm font-medium">
                    {{ __('partner.address_search') }}
                </label>

                <input
                    type="text"
                    wire:model.live.debounce.300ms="addressSearch"
                    placeholder="{{ __('partner.search_address_placeholder') }}"
                    class="mb-2 w-full rounded border"
                    @disabled(! $seasonId)
                >

                <select
                    wire:model.live="partnerAddressId"
                    class="w-full rounded border-gray-300 bg-yellow-100"
                    @disabled(! $seasonId)
                >
                    <option value="">{{ __('partner.select_address') }}</option>

                    @foreach ($this->addresses as $address)
                        <option value="{{ $address['id'] }}">
                            {{ collect([
                                trim(($address['partner_code'] ? $address['partner_code'] . ' - ' : '') . $address['partner_name']),
                                $address['address_name'],
                                $address['address'],
                            ])->filter()->implode(' - ') }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- 2. sor --}}
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium">
                    {{ __('partner.brand') }}
                </label>

                <select
                    wire:model.live="brandId"
                    class="w-full rounded border-gray-300 bg-yellow-100"
                    @disabled(! $partnerAddressId)
                >
                    <option value="">{{ __('partner.select_brand') }}</option>

                    @foreach ($this->brands as $brand)
                        <option value="{{ $brand->id }}">
                            {{ $brand->translated_name ?? $brand->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium">
                    {{ __('partner.order_sheet') }}
                </label>

                <select
                    wire:model.live="orderSheetTypeId"
                    class="w-full rounded border-gray-300 bg-yellow-100"
                    @disabled(! $brandId)
                >
                    <option value="">{{ __('partner.select_order_sheet') }}</option>

                    @foreach ($this->orderSheetTypes as $type)
                        <option value="{{ $type->id }}">
                            {{ $type->translated_name ?? $type->translate('name') }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if ($seasonId && $partnerAddressId && $brandId && $orderSheetTypeId)
                <div class="md:col-span-6">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-gray-900">
                            {{ __('partner.existing_orders') }}
                        </h2>

                        <span class="text-xs text-gray-500">
                            {{ trans_choice('partner.order_count', $this->contextOrders->count(), ['count' => $this->contextOrders->count()]) }}
                        </span>
                    </div>

                    @if ($this->contextOrders->isEmpty())
                        <div class="rounded border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                            {{ __('partner.no_existing_orders') }}
                        </div>
                    @else
                        <div class="overflow-x-auto rounded border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50 text-left text-gray-700">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('partner.reference_number') }}</th>
                                        <th class="px-3 py-2">{{ __('partner.order_type') }}</th>
                                        <th class="px-3 py-2">{{ __('partner.status') }}</th>
                                        <th class="px-3 py-2 text-right"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    @foreach ($this->contextOrders as $contextOrder)
                                        <tr wire:key="context-order-{{ $contextOrder->id }}">
                                            <td class="whitespace-nowrap px-3 py-2 font-semibold">
                                                {{ $contextOrder->reference_number }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2">
                                                {{ $contextOrder->orderType?->code }}
                                                @if ($contextOrder->orderType)
                                                    – {{ $contextOrder->orderType->translate('name') }}
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2">
                                                {{ $contextOrder->isSubmitted()
                                                    ? __('partner.status_submitted')
                                                    : __('partner.status_draft') }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    wire:click="openExistingOrder({{ $contextOrder->id }})"
                                                    class="rounded bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-600"
                                                >
                                                    {{ __('partner.open_order') }}
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">
                        {{ __('partner.reference_number') }}
                    </label>

                    <input
                        type="text"
                        wire:model.live.debounce.300ms="referenceNumber"
                        maxlength="100"
                        class="w-full rounded border-gray-300 bg-yellow-100"
                        placeholder="{{ __('partner.reference_number_placeholder') }}"
                    >

                    @error('referenceNumber')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium">
                        {{ __('partner.order_type') }}
                    </label>

                    <select
                        wire:model.live="orderTypeId"
                        class="w-full rounded border-gray-300 bg-yellow-100"
                    >
                        <option value="">{{ __('partner.select_order_type') }}</option>

                        @foreach ($this->orderTypes as $orderType)
                            <option value="{{ $orderType->id }}">
                                {{ $orderType->code }} – {{ $orderType->translated_name ?? $orderType->name }}
                            </option>
                        @endforeach
                    </select>

                    @error('orderTypeId')
                        <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex items-end justify-end md:col-span-2">
                    @if ($this->canProceed)
                        <button
                            type="button"
                            wire:click="proceed"
                            class="whitespace-nowrap rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-slate-800"
                        >
                            {{ __('partner.create_order') }}
                        </button>
                    @else
                        <button
                            type="button"
                            disabled
                            class="whitespace-nowrap rounded-lg bg-slate-300 px-5 py-2.5 text-sm font-semibold text-white"
                        >
                            {{ __('partner.create_order') }}
                        </button>
                    @endif
                </div>
            @endif

        </div>
    </div>
