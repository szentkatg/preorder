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

<style>
    .pricing-calculation-details {
        --pricing-detail-border: rgb(156 163 175);
        --pricing-detail-border-soft: rgb(209 213 219);
        --pricing-detail-header-bg: rgb(243 244 246);
        --pricing-detail-total-bg: rgb(249 250 251);
        --pricing-detail-selected-bg: rgb(239 246 255);
        --pricing-detail-text: rgb(17 24 39);
        --pricing-detail-muted: rgb(75 85 99);
    }

    .dark .pricing-calculation-details {
        --pricing-detail-border: rgb(75 85 99);
        --pricing-detail-border-soft: rgb(55 65 81);
        --pricing-detail-header-bg: rgb(31 41 55);
        --pricing-detail-total-bg: rgb(17 24 39);
        --pricing-detail-selected-bg: rgb(30 58 138 / 0.35);
        --pricing-detail-text: rgb(243 244 246);
        --pricing-detail-muted: rgb(209 213 219);
    }

    .pricing-detail-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--pricing-detail-border);
        border-radius: 0.65rem;
        background: white;
    }

    .dark .pricing-detail-table-wrap {
        background: rgb(17 24 39);
    }

    .pricing-detail-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        border-spacing: 0;
        color: var(--pricing-detail-text);
        font-size: 0.82rem;
        line-height: 1.35;
    }

    .pricing-detail-table--candidates {
        min-width: 1280px;
    }

    .pricing-detail-table caption {
        padding: 0.75rem 1rem;
        caption-side: top;
        color: var(--pricing-detail-muted);
        font-size: 0.78rem;
        text-align: left;
    }

    .pricing-detail-table th,
    .pricing-detail-table td {
        border: 1px solid var(--pricing-detail-border);
        padding: 0.65rem 0.85rem;
        vertical-align: middle;
        white-space: nowrap;
    }

    .pricing-detail-table th {
        background: var(--pricing-detail-header-bg);
        color: var(--pricing-detail-text);
        font-weight: 700;
        text-align: left;
    }

    .pricing-detail-table td {
        background: transparent;
    }

    .pricing-detail-table .numeric {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .pricing-detail-table .muted {
        color: var(--pricing-detail-muted);
    }

    .pricing-detail-table .strong {
        font-weight: 700;
    }

    .pricing-detail-table .total-row td {
        background: var(--pricing-detail-total-bg);
        font-weight: 700;
    }

    .pricing-detail-table .selected-row td {
        background: var(--pricing-detail-selected-bg);
    }

    .pricing-detail-badge {
        display: inline-block;
        margin-left: 0.5rem;
        padding: 0.12rem 0.45rem;
        border-radius: 999px;
        background: rgb(219 234 254);
        color: rgb(29 78 216);
        font-size: 0.68rem;
        font-weight: 700;
    }

    .dark .pricing-detail-badge {
        background: rgb(30 64 175);
        color: rgb(219 234 254);
    }
</style>

<div class="pricing-calculation-details space-y-6 text-sm">
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

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Kiválasztott ár költségelemekre bontva</h3>

        <div class="pricing-detail-table-wrap">
            <table class="pricing-detail-table">
                <caption>Az aktuálisan kiválasztott beszerzési ár bekerülési értékének részletes felbontása.</caption>
                <thead>
                    <tr>
                        <th>Költségelem</th>
                        <th class="numeric">Számítás alapja</th>
                        <th class="numeric">%</th>
                        <th class="numeric">Érték HUF</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="strong">Beszerzési ár HUF-ban</td>
                        <td class="numeric">
                            {{ number_format((float) $record->purchase_price, 4, ',', ' ') }}
                            ×
                            {{ number_format((float) $record->exchange_rate, 4, ',', ' ') }}
                        </td>
                        <td class="numeric muted">-</td>
                        <td class="numeric strong">{{ number_format($selectedBreakdown['base_huf'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr>
                        <td class="strong">Szállítási költség</td>
                        <td class="numeric">{{ number_format($selectedBreakdown['base_huf'], 2, ',', ' ') }}</td>
                        <td class="numeric">{{ number_format((float) $record->shipping_cost_percent, 2, ',', ' ') }}%</td>
                        <td class="numeric strong">{{ number_format($selectedBreakdown['shipping_amount'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr>
                        <td class="strong">Vám</td>
                        <td class="numeric">{{ number_format($selectedBreakdown['after_shipping'], 2, ',', ' ') }}</td>
                        <td class="numeric">{{ number_format((float) $record->customs_percent, 2, ',', ' ') }}%</td>
                        <td class="numeric strong">{{ number_format($selectedBreakdown['customs_amount'], 2, ',', ' ') }}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Bekerülési érték</td>
                        <td class="numeric muted">-</td>
                        <td class="numeric muted">-</td>
                        <td class="numeric">{{ number_format($selectedBreakdown['landed_cost'], 2, ',', ' ') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="mb-3 font-semibold">Összehasonlított aktív beszerzési árak</h3>

        @if (count($candidates) > 0)
            <div class="pricing-detail-table-wrap">
                <table class="pricing-detail-table pricing-detail-table--candidates">
                    <caption>A kalkulációban vizsgált aktív beszerzési árak és a hozzájuk tartozó költségelemek.</caption>
                    <thead>
                        <tr>
                            <th>Beszállító</th>
                            <th>Ország</th>
                            <th class="numeric">Besz. ár</th>
                            <th>Deviza</th>
                            <th class="numeric">Árfolyam</th>
                            <th class="numeric">Alap HUF</th>
                            <th class="numeric">Száll. %</th>
                            <th class="numeric">Száll. HUF</th>
                            <th class="numeric">Vám %</th>
                            <th class="numeric">Vám HUF</th>
                            <th class="numeric">Bekerülési HUF</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($candidates as $candidate)
                            @php
                                $breakdown = $costBreakdown($candidate);
                                $isSelected = $selectedPurchasePriceId !== null
                                    && (int) ($candidate['product_purchase_price_id'] ?? 0) === (int) $selectedPurchasePriceId;
                            @endphp
                            <tr @class([
                                'selected-row' => $isSelected,
                            ])>
                                <td class="strong">
                                    {{ $candidate['supplier_name'] ?? $candidate['supplier_code'] ?? '-' }}
                                    @if ($isSelected)
                                        <span class="pricing-detail-badge">
                                            kiválasztva
                                        </span>
                                    @endif
                                </td>
                                <td>{{ $candidate['supplier_country_code'] ?? '-' }}</td>
                                <td class="numeric">{{ number_format((float) ($candidate['purchase_price'] ?? 0), 4, ',', ' ') }}</td>
                                <td>{{ $candidate['currency'] ?? '-' }}</td>
                                <td class="numeric">{{ number_format((float) ($candidate['exchange_rate'] ?? 0), 4, ',', ' ') }}</td>
                                <td class="numeric">{{ number_format($breakdown['base_huf'], 2, ',', ' ') }}</td>
                                <td class="numeric">{{ number_format((float) ($candidate['shipping_cost_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="numeric">{{ number_format($breakdown['shipping_amount'], 2, ',', ' ') }}</td>
                                <td class="numeric">{{ number_format((float) ($candidate['customs_percent'] ?? 0), 2, ',', ' ') }}%</td>
                                <td class="numeric">{{ number_format($breakdown['customs_amount'], 2, ',', ' ') }}</td>
                                <td class="numeric strong">{{ number_format((float) ($candidate['landed_cost_huf'] ?? $breakdown['landed_cost']), 2, ',', ' ') }}</td>
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
