<div class="mx-auto max-w-screen-2xl p-6">
    <div class="mb-4 rounded-lg border bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-bold">
                {{ __('partner.partner_order_coverage') }}
            </h1>

            <div class="flex flex-wrap justify-end gap-2">
                <a
                    href="{{ route('partner.orders.select') }}"
                    class="rounded bg-gray-700 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-600"
                >
                    {{ __('partner.back_to_order_selector') }}
                </a>
                <button
                    type="button"
                    wire:click="export"
                    class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600"
                >
                    {{ __('partner.excel_export') }}
                </button>
            </div>
        </div>
    </div>

    <div class="mb-4 rounded-lg border bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('partner.search') }}"
                class="rounded border-gray-300"
            >

            <select wire:model.live="seasonId" class="rounded border-gray-300">
                <option value="">
                    {{ __('partner.all_seasons') }}
                </option>

                @foreach ($this->seasons as $season)
                    <option value="{{ $season->id }}">
                        {{ $season->name }}
                    </option>
                @endforeach
            </select>

            <label class="flex items-center gap-2 rounded border border-gray-300 px-3 py-2 text-sm">
                <input
                    type="checkbox"
                    wire:model.live="onlyMissing"
                    class="rounded border-gray-300"
                >

                <span>
                    {{ __('partner.only_missing_or_empty_orders') }}
                </span>
            </label>
        </div>
    </div>

    @php
        $coverage = $this->coverage;
        $brands = $coverage['brands'] ?? [];
        $rows = $coverage['rows'] ?? [];
        $totals = $coverage['totals'] ?? [];

        $totalColspan = 3;

        foreach ($brands as $brand) {
            $totalColspan += count($brand['columns'] ?? []) + 1;
        }

        $totalColspan += 1;

        $cellClasses = [
            'disabled' => 'border bg-gray-200 px-3 py-2 text-center text-gray-400',
            'missing' => 'border bg-red-100 px-3 py-2 text-center font-semibold text-red-800',
            'empty' => 'border bg-yellow-100 px-3 py-2 text-center font-semibold text-yellow-800',
            'filled' => 'border bg-green-100 px-3 py-2 text-center font-semibold text-green-800',
        ];
    @endphp

    <div class="rounded-lg border bg-white shadow-sm">
        <div class="max-h-[70vh] overflow-auto">
            <table class="min-w-full border-collapse text-sm">
                <thead class="sticky top-0 z-30 bg-white shadow-sm">
                    <tr>
                        <th rowspan="2" class="sticky left-0 z-40 min-w-[120px] border bg-white px-3 py-2 text-left">
                            {{ __('partner.partner_code') }}
                        </th>

                        <th rowspan="2" class="sticky left-[120px] z-40 min-w-[220px] border bg-white px-3 py-2 text-left">
                            {{ __('partner.partner') }}
                        </th>

                        <th rowspan="2" class="sticky left-[340px] z-40 min-w-[260px] border bg-white px-3 py-2 text-left">
                            {{ __('partner.address') }}
                        </th>

                        @foreach ($brands as $brand)
                            <th
                                colspan="{{ count($brand['columns'] ?? []) + 1 }}"
                                class="border bg-gray-100 px-3 py-2 text-center font-semibold"
                            >
                                {{ $brand['name'] }}
                            </th>
                        @endforeach

                        <th rowspan="2" class="min-w-[120px] border bg-blue-100 px-3 py-2 text-center font-bold">
                            {{ __('partner.grand_total') }}
                        </th>
                    </tr>

                    <tr>
                        @foreach ($brands as $brand)
                            @foreach ($brand['columns'] as $column)
                                <th class="min-w-[100px] border bg-white px-3 py-2 text-center">
                                    {{ $column['order_sheet'] }}
                                </th>
                            @endforeach

                            <th class="min-w-[100px] border bg-blue-50 px-3 py-2 text-center font-bold">
                                {{ __('partner.total') }}
                            </th>
                        @endforeach
                    </tr>

                    <tr class="bg-blue-50 font-semibold">
                        <th colspan="3" class="sticky left-0 z-40 border bg-blue-50 px-3 py-2 text-left">
                            {{ __('partner.total_quantity') }}
                        </th>

                        @foreach ($brands as $brand)
                            @foreach ($brand['columns'] as $column)
                                <th class="border bg-blue-50 px-3 py-2 text-center">
                                    {{ number_format((int) ($totals['columns'][$column['index']] ?? 0), 0, ',', ' ') }}
                                </th>
                            @endforeach

                            <th class="border bg-blue-100 px-3 py-2 text-center font-bold">
                                {{ number_format((int) ($totals['brands'][$brand['id']] ?? 0), 0, ',', ' ') }}
                            </th>
                        @endforeach

                        <th class="border bg-blue-200 px-3 py-2 text-center font-bold">
                            {{ number_format((int) ($totals['grand_total'] ?? 0), 0, ',', ' ') }}
                        </th>
                    </tr>

                    <tr class="bg-indigo-50 font-semibold">
                        <th colspan="3" class="sticky left-0 z-40 border bg-indigo-50 px-3 py-2 text-left">
                            {{ __('partner.filled_order_sheets') }}
                        </th>

                        @foreach ($brands as $brand)
                            @foreach ($brand['columns'] as $column)
                                <th class="border bg-indigo-50 px-3 py-2 text-center">
                                    {{ number_format((int) ($totals['filled_counts'][$column['index']] ?? 0), 0, ',', ' ') }}
                                </th>
                            @endforeach

                            <th class="border bg-indigo-100 px-3 py-2 text-center font-bold">
                                {{ number_format((int) ($totals['brand_filled_counts'][$brand['id']] ?? 0), 0, ',', ' ') }}
                            </th>
                        @endforeach

                        <th class="border bg-indigo-200 px-3 py-2 text-center font-bold">
                            {{ number_format((int) ($totals['grand_filled_count'] ?? 0), 0, ',', ' ') }}
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="sticky left-0 z-20 border bg-white px-3 py-2">
                                {{ $row['partner_code'] }}
                            </td>

                            <td class="sticky left-[120px] z-20 border bg-white px-3 py-2 font-medium">
                                {{ $row['partner_name'] }}
                            </td>

                            <td class="sticky left-[340px] z-20 border bg-white px-3 py-2">
                                <div class="font-medium">
                                    {{ $row['address_name'] }}
                                </div>

                                <div class="text-xs text-gray-500">
                                    {{ $row['address'] }}
                                </div>
                            </td>

                            @foreach ($brands as $brand)
                                @foreach ($brand['columns'] as $column)
                                    @php
                                        $cell = $row['cells'][$column['index']] ?? null;
                                        $status = $cell['status'] ?? 'disabled';
                                        $quantity = (int) ($cell['quantity'] ?? 0);
                                        $class = $cellClasses[$status] ?? $cellClasses['disabled'];
                                    @endphp

                                    <td class="{{ $class }}">
                                        @if (! $cell || ! ($cell['enabled'] ?? false))
                                            —
                                        @else
                                            <button
                                                type="button"
                                                wire:click="openCoverageCell(
                                                    {{ $cell['order_id'] ?? 'null' }},
                                                    {{ $cell['partner_address_id'] }},
                                                    {{ $cell['brand_id'] }},
                                                    {{ $cell['order_sheet_type_id'] }}
                                                )"
                                                class="underline hover:no-underline"
                                            >
                                                {{ number_format($quantity, 0, ',', ' ') }}
                                            </button>
                                        @endif
                                    </td>
                                @endforeach

                                <td class="border bg-blue-50 px-3 py-2 text-center font-bold">
                                    {{ number_format((int) ($row['brand_totals'][$brand['id']] ?? 0), 0, ',', ' ') }}
                                </td>
                            @endforeach

                            <td class="border bg-blue-100 px-3 py-2 text-center font-bold">
                                {{ number_format((int) ($row['grand_total'] ?? 0), 0, ',', ' ') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="{{ $totalColspan }}"
                                class="border px-3 py-8 text-center text-gray-500"
                            >
                                {{ __('partner.no_data') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <div class="rounded border bg-gray-200 p-3 text-sm text-gray-700">
            {{ __('partner.not_available') }}
        </div>

        <div class="rounded border bg-red-100 p-3 text-sm text-red-800">
            {{ __('partner.no_order_created') }}
        </div>

        <div class="rounded border bg-yellow-100 p-3 text-sm text-yellow-800">
            {{ __('partner.order_created_but_empty') }}
        </div>

        <div class="rounded border bg-green-100 p-3 text-sm text-green-800">
            {{ __('partner.order_has_quantity') }}
        </div>
    </div>
</div>
