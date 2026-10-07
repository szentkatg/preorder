@php
    /** @var \App\Models\PricingCalculationRow $record */
    $snapshot = $record->calculation_snapshot ?? [];
    $selected = $snapshot['selected_purchase_price'] ?? [];
    $candidates = $snapshot['compared_candidates'] ?? [];
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
        <h3 class="mb-3 font-semibold">Összehasonlított aktív beszerzési árak</h3>

        @if (count($candidates) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-2 py-2">Beszállító</th>
                            <th class="px-2 py-2">Ország</th>
                            <th class="px-2 py-2 text-right">Besz. ár</th>
                            <th class="px-2 py-2">Deviza</th>
                            <th class="px-2 py-2 text-right">Árfolyam</th>
                            <th class="px-2 py-2 text-right">Száll. %</th>
                            <th class="px-2 py-2 text-right">Vám %</th>
                            <th class="px-2 py-2 text-right">Bekerülési HUF</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($candidates as $candidate)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-2">{{ $candidate['supplier_name'] ?? $candidate['supplier_code'] ?? '-' }}</td>
                                <td class="px-2 py-2">{{ $candidate['supplier_country_code'] ?? '-' }}</td>
                                <td class="px-2 py-2 text-right">{{ number_format((float) ($candidate['purchase_price'] ?? 0), 4, ',', ' ') }}</td>
                                <td class="px-2 py-2">{{ $candidate['currency'] ?? '-' }}</td>
                                <td class="px-2 py-2 text-right">{{ number_format((float) ($candidate['exchange_rate'] ?? 0), 4, ',', ' ') }}</td>
                                <td class="px-2 py-2 text-right">{{ number_format((float) ($candidate['shipping_cost_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="px-2 py-2 text-right">{{ number_format((float) ($candidate['customs_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="px-2 py-2 text-right font-semibold">{{ number_format((float) ($candidate['landed_cost_huf'] ?? 0), 2, ',', ' ') }}</td>
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
