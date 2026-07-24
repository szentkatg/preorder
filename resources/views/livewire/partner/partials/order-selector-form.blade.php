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
                            {{ app()->getLocale() === 'en'
                                ? ($type->name_en ?? $type->name_hu)
                                : ($type->name_hu ?? $type->name_en) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end md:col-span-2">
                @if ($seasonId && $partnerAddressId && $brandId && $orderSheetTypeId)
                    @if ($this->selectedOrder)
                        <button
                            type="button"
                            wire:click="openOrder"
                            class="w-full rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-slate-800"
                        >
                            {{ __('partner.open_order') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="proceed"
                            class="w-full rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow hover:bg-slate-800"
                        >
                            {{ __('partner.load_order_sheet') }}
                        </button>
                    @endif
                @endif
            </div>

        </div>
    </div>
