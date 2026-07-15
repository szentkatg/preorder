<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Beállítások
            </x-slot>

            <x-slot name="description">
                Válaszd ki az azonos szezonhoz, márkához és
                rendelőlap-típushoz tartozó arány- és készletrendelést.
            </x-slot>

            {{ $this->form }}
        </x-filament::section>

        @if ($errorMessage)
            <x-filament::section>
                <x-slot name="heading">
                    Hiba
                </x-slot>

                <div class="text-sm text-danger-600">
                    {{ $errorMessage }}
                </div>
            </x-filament::section>
        @endif

        @if (! empty($preview))
            @if ($preview['applied'] ?? false)
                <div
                    class="rounded-lg border border-success-300
                        bg-success-50 p-4 text-sm text-success-700"
                >
                    A készletrendelés felülírása sikeresen megtörtént.
                </div>
            @endif

            <x-filament::section>
                <x-slot name="heading">
                    Összesítés
                </x-slot>

                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2
                        lg:grid-cols-4"
                >
                    <div class="rounded-lg border p-4">
                        <div class="text-sm text-gray-500">
                            Partneri rendelések
                        </div>

                        <div class="text-2xl font-semibold">
                            {{ number_format(
                                $preview['summary']
                                    ['partner_quantity'] ?? 0,
                                0,
                                ',',
                                ' '
                            ) }}
                        </div>
                    </div>

                    <div class="rounded-lg border p-4">
                        <div class="text-sm text-gray-500">
                            Eredeti készletterv
                        </div>

                        <div class="text-2xl font-semibold">
                            {{ number_format(
                                $preview['summary']
                                    ['original_stock_quantity'] ?? 0,
                                0,
                                ',',
                                ' '
                            ) }}
                        </div>
                    </div>

                    <div class="rounded-lg border p-4">
                        <div class="text-sm text-gray-500">
                            Új készletkorrekció
                        </div>

                        <div class="text-2xl font-semibold">
                            {{ number_format(
                                $preview['summary']
                                    ['new_stock_quantity'] ?? 0,
                                0,
                                ',',
                                ' '
                            ) }}
                        </div>
                    </div>

                    <div class="rounded-lg border p-4">
                        <div class="text-sm text-gray-500">
                            Végső összmennyiség
                        </div>

                        <div class="text-2xl font-semibold">
                            {{ number_format(
                                $preview['summary']
                                    ['final_quantity'] ?? 0,
                                0,
                                ',',
                                ' '
                            ) }}
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-sm text-gray-600">
                    Feldolgozott partneri rendelések:
                    <strong>
                        {{ $preview['scope']['partner_order_count'] ?? 0 }}
                    </strong>

                    · Termék–szín kombinációk:
                    <strong>
                        {{ $preview['summary']['group_count'] ?? 0 }}
                    </strong>

                    · Feldolgozott méretek:
                    <strong>
                        {{ $preview['summary']['size_count'] ?? 0 }}
                    </strong>

                    · Negatív korrekciók:
                    <strong>
                        {{
                            $preview['summary']
                                ['negative_correction_count'] ?? 0
                        }}
                    </strong>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">
                    Számítási előnézet
                </x-slot>

                <div class="overflow-x-auto">
                    <table
                        class="w-full min-w-[1000px] border-collapse
                            text-sm"
                    >
                        <thead>
                            <tr class="border-b bg-gray-50 text-left">
                                <th class="px-3 py-2">Modell</th>
                                <th class="px-3 py-2">Szín</th>
                                <th class="px-3 py-2 text-right">
                                    Partner
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Készletterv
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Arányösszeg
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Új készlet
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Végösszesen
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($preview['groups'] ?? [] as $group)
                                <tr class="border-b bg-gray-100 font-semibold">
                                    <td class="px-3 py-2">
                                        {{ $group['model_code'] }}

                                        @if ($group['product_name'])
                                            <div
                                                class="text-xs font-normal
                                                    text-gray-500"
                                            >
                                                {{ $group['product_name'] }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-3 py-2">
                                        {{ $group['color_code'] }}

                                        @if ($group['color_name'])
                                            <div
                                                class="text-xs font-normal
                                                    text-gray-500"
                                            >
                                                {{ $group['color_name'] }}
                                            </div>
                                        @endif
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        {{ $group['partner_quantity'] }}
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        {{ $group['planned_stock_total'] }}
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        {{ $group['ratio_sum'] }}
                                    </td>

                                    <td
                                        class="px-3 py-2 text-right
                                            {{
                                                $group['new_stock_quantity'] < 0
                                                    ? 'text-danger-600'
                                                    : ''
                                            }}"
                                    >
                                        {{ $group['new_stock_quantity'] }}
                                    </td>

                                    <td class="px-3 py-2 text-right">
                                        {{ $group['final_quantity'] }}
                                    </td>
                                </tr>

                                @foreach ($group['items'] as $item)
                                    <tr class="border-b">
                                        <td
                                            class="px-3 py-1.5 pl-8
                                                text-gray-500"
                                            colspan="2"
                                        >
                                            Méret:
                                            <strong>
                                                {{ $item['size_code'] }}
                                            </strong>

                                            <span class="ml-3 text-xs">
                                                {{ $item['sku_code'] }}
                                            </span>
                                        </td>

                                        <td class="px-3 py-1.5 text-right">
                                            {{ $item['partner_quantity'] }}
                                        </td>

                                        <td class="px-3 py-1.5 text-right">
                                            {{
                                                number_format(
                                                    $item[
                                                        'raw_allocated_stock'
                                                    ],
                                                    4,
                                                    ',',
                                                    ' '
                                                )
                                            }}
                                        </td>

                                        <td class="px-3 py-1.5 text-right">
                                            {{ $item['ratio'] }}
                                        </td>

                                        <td
                                            class="px-3 py-1.5 text-right
                                                {{
                                                    $item[
                                                        'new_stock_quantity'
                                                    ] < 0
                                                        ? 'font-semibold
                                                            text-danger-600'
                                                        : ''
                                                }}"
                                        >
                                            {{ $item['new_stock_quantity'] }}
                                        </td>

                                        <td class="px-3 py-1.5 text-right">
                                            {{ $item['final_quantity'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>