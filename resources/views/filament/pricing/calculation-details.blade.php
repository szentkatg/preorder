@php
    /** @var \App\Models\PricingCalculationRow $record */
    $snapshot = $record->calculation_snapshot ?? [];
    $selected = $snapshot['selected_purchase_price'] ?? [];
    $candidates = $snapshot['compared_candidates'] ?? [];
    $selectedPurchasePriceId = $selected['id'] ?? null;

    $costBreakdown = function (array $data): array {
        $purchasePrice = (float) ($data['purchase_price'] ?? 0);
        $exchangeRate = (float) ($data['exchange_rate'] ?? 0);
        $shippingPercent = (float) ($data['shipping_cost_percent'] ?? 0);
        $customsPercent = (float) ($data['customs_percent'] ?? 0);

        $baseHuf = $purchasePrice * $exchangeRate;
        $shippingAmount = $baseHuf * ($shippingPercent / 100);
        $afterShipping = $baseHuf + $shippingAmount;
        $customsAmount = $afterShipping * ($customsPercent / 100);
        $landedCost = $afterShipping + $customsAmount;

        return [
            'base_huf' => $baseHuf,
            'shipping_amount' => $shippingAmount,
            'after_shipping' => $afterShipping,
            'customs_amount' => $customsAmount,
            'landed_cost' => $landedCost,
        ];
    };

    $selectedBreakdown = $costBreakdown([
        'purchase_price' => $record->purchase_price,
        'exchange_rate' => $record->exchange_rate,
        'shipping_cost_percent' => $record->shipping_cost_percent,
        'customs_percent' => $record->customs_percent,
    ]);
@endphp

<div class="space-y-6 text-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="mb-3 font-semibold">Kiválasztott beszerzési ár</h3>
            <dl class="grid grid-cols-2 gap-2">
                <dt class="text-gray-500">Beszállító</dt>
                <dd>{{ $record->supplier?->short_name ?: $record->supplier?->name ?: '-' }}</dd>
                <dt class="text-gray-500">Beszerzési ár</dt>
                <dd>{{ number_format((float) $record->purchase_price, 4, ',', ' ') }} {{ $record->purchaseCurrency?->code }}</dd>
                <dt class="text-gray-500">Árfolyam</dt>
                <dd>{{ number_format((float) $record->exchange_rate, 4, ',', ' ') }}</dd>
                <dt class="text-gray-500">Szállítás %</dt>
                <dd>{{ number_format((float) $record->shipping_cost_percent, 2, ',', ' ') }}%</dd>
                <dt class="text-gray-500">Vám %</dt>
                <dd>{{ number_format((float) $record->customs_percent, 2, ',', ' ') }}%</dd>
                <dt class="text-gray-500">Bekerülési érték</dt>
                <dd class="font-semibold">{{ number_format((float) $record->calculated_price, 2, ',', ' ') }} {{ $record->currency?->code }}</dd>
            </dl>
        </div>

        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="mb-3 font-semibold">Számítás</h3>
            <p class="font-mono text-xs">
                {{ $snapshot['formula'] ?? 'purchase_price * exchange_rate * (1 + shipping_cost_percent / 100) * (1 + customs_percent / 100)' }}
            </p>
            <p class="mt-3 text-gray-600 dark:text-gray-300">
                {{ $snapshot['selection_rule'] ?? 'Több aktív beszerzési ár esetén a legalacsonyabb HUF bekerülési érték kerül kiválasztásra.' }}
            </p>
            <p class="mt-3 text-gray-500">
                Kalkulálva: {{ $snapshot['calculated_at'] ?? '-' }}
            </p>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Kiválasztott ár költségelemekre bontva</h3>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full border-collapse text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="border-b border-r border-gray-200 px-4 py-3 text-left dark:border-gray-700">Költségelem</th>
                        <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Számítás alapja</th>
                        <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">%</th>
                        <th class="border-b border-gray-200 px-4 py-3 text-right dark:border-gray-700">Érték HUF</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr>
                        <td class="border-r border-gray-200 px-4 py-3 font-medium dark:border-gray-700">Beszerzési ár HUF-ban</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">
                            {{ number_format((float) $record->purchase_price, 4, ',', ' ') }}
                            ×
                            {{ number_format((float) $record->exchange_rate, 4, ',', ' ') }}
                        </td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right text-gray-500 dark:border-gray-700">-</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($selectedBreakdown['base_huf'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr>
                        <td class="border-r border-gray-200 px-4 py-3 font-medium dark:border-gray-700">Szállítási költség</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format($selectedBreakdown['base_huf'], 2, ',', ' ') }}</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) $record->shipping_cost_percent, 2, ',', ' ') }}%</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($selectedBreakdown['shipping_amount'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr>
                        <td class="border-r border-gray-200 px-4 py-3 font-medium dark:border-gray-700">Vám</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format($selectedBreakdown['after_shipping'], 2, ',', ' ') }}</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) $record->customs_percent, 2, ',', ' ') }}%</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($selectedBreakdown['customs_amount'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr class="bg-gray-50 dark:bg-gray-900">
                        <td class="border-r border-gray-200 px-4 py-3 font-semibold dark:border-gray-700">Bekerülési érték</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right text-gray-500 dark:border-gray-700">-</td>
                        <td class="border-r border-gray-200 px-4 py-3 text-right text-gray-500 dark:border-gray-700">-</td>
                        <td class="px-4 py-3 text-right text-base font-bold">{{ number_format($selectedBreakdown['landed_cost'], 2, ',', ' ') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Összehasonlított aktív beszerzési árak</h3>

        @if (count($candidates) > 0)
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full border-collapse text-left text-xs">
                    <thead class="bg-gray-50 text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                        <tr>
                            <th class="border-b border-r border-gray-200 px-4 py-3 dark:border-gray-700">Beszállító</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 dark:border-gray-700">Ország</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Besz. ár</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 dark:border-gray-700">Deviza</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Árfolyam</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Alap HUF</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Száll. %</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Száll. HUF</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Vám %</th>
                            <th class="border-b border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">Vám HUF</th>
                            <th class="border-b border-gray-200 px-4 py-3 text-right dark:border-gray-700">Bekerülési HUF</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($candidates as $candidate)
                            @php
                                $breakdown = $costBreakdown($candidate);
                                $isSelected = $selectedPurchasePriceId !== null
                                    && (int) ($candidate['product_purchase_price_id'] ?? 0) === (int) $selectedPurchasePriceId;
                            @endphp
                            <tr @class([
                                'bg-blue-50 dark:bg-blue-950/30' => $isSelected,
                            ])>
                                <td class="border-r border-gray-200 px-4 py-3 font-medium dark:border-gray-700">
                                    {{ $candidate['supplier_name'] ?? $candidate['supplier_code'] ?? '-' }}
                                    @if ($isSelected)
                                        <span class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-200">
                                            kiválasztva
                                        </span>
                                    @endif
                                </td>
                                <td class="border-r border-gray-200 px-4 py-3 dark:border-gray-700">{{ $candidate['supplier_country_code'] ?? '-' }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) ($candidate['purchase_price'] ?? 0), 4, ',', ' ') }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 dark:border-gray-700">{{ $candidate['currency'] ?? '-' }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) ($candidate['exchange_rate'] ?? 0), 4, ',', ' ') }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format($breakdown['base_huf'], 2, ',', ' ') }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) ($candidate['shipping_cost_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format($breakdown['shipping_amount'], 2, ',', ' ') }}</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format((float) ($candidate['customs_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="border-r border-gray-200 px-4 py-3 text-right dark:border-gray-700">{{ number_format($breakdown['customs_amount'], 2, ',', ' ') }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ number_format((float) ($candidate['landed_cost_huf'] ?? $breakdown['landed_cost']), 2, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500">Ehhez a sorhoz nincs eltárolt alternatív beszerzési ár.</p>
        @endif
    </div>

    <details class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <summary class="cursor-pointer font-semibold">Nyers JSON snapshot</summary>
        <pre class="mt-4 max-h-96 overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </details>
</div>
