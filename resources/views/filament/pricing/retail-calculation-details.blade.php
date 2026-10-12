@php
    $snapshot = $record->calculation_snapshot ?? [];
    $inputs = $snapshot['inputs'] ?? [];
    $rounding = $snapshot['rounding'] ?? [];
    $parameter = $snapshot['retail_parameter'] ?? [];
    $scope = match ($parameter['scope_type'] ?? '') {
        'product' => 'Modell', 'item_main_group' => 'Főcsoport', 'supplier_country' => 'Beszállító ország', default => 'Globális',
    };
    $mode = match ($rounding['mode'] ?? '') { 'up' => 'Felfelé', 'down' => 'Lefelé', default => 'Legközelebbi' };
@endphp
<div class="pricing-retail-details">
    <style>
        .pricing-retail-details { color: rgb(17 24 39); }
        .dark .pricing-retail-details { color: rgb(243 244 246); }
        .pricing-retail-details .retail-details-wrap { overflow-x: auto; }
        .pricing-retail-details table { width: 100%; min-width: 650px; border-collapse: collapse; font-size: 0.875rem; }
        .pricing-retail-details th, .pricing-retail-details td { border: 1px solid rgb(156 163 175); padding: 0.5rem 0.75rem; text-align: left; }
        .pricing-retail-details th { background: rgb(243 244 246); }
        .dark .pricing-retail-details th { background: rgb(31 41 55); }
        .pricing-retail-details .numeric { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pricing-retail-details .retail-total { font-weight: 700; }
    </style>
    <p class="mb-3">{{ $record->product?->model_code }} · {{ $snapshot['price_list']['code'] ?? $record->priceList?->code }} · Kalkulálva: {{ $snapshot['calculated_at'] ?? '-' }}</p>
    @if ($record->requires_review)
        <p class="mb-3 font-semibold">A bemeneti adatok megváltoztak. A korábbi végleges ár megmaradt. A HUF kisker újraszámítás frissíti a kalkulációt; ezután dönthetsz az ár megtartásáról vagy az új kalkulált ár elfogadásáról. A felülvizsgálat akkor is szükséges lehet, ha a kerekített ár változatlan.</p>
    @endif
    <div class="retail-details-wrap">
        <table>
            <thead><tr><th>Lépés / bemeneti adat</th><th>Szabály / szűkítés</th><th class="numeric">Érték</th></tr></thead>
            <tbody>
                <tr><td>Végleges bekerülési érték</td><td>Mentett HUF kalkulációs sor #{{ $snapshot['source_cost_row_id'] ?? '-' }}</td><td class="numeric">{{ number_format((float) ($inputs['base_price'] ?? 0), 4, ',', ' ') }} HUF</td></tr>
                <tr><td>Kisker szorzó</td><td>{{ $scope }} · Paraméter #{{ $parameter['id'] ?? '-' }}</td><td class="numeric">{{ rtrim(rtrim(number_format((float) ($inputs['multiplier'] ?? 0), 6, ',', ''), '0'), ',') }}</td></tr>
                <tr><td>Kerekítés előtti bruttó kisker ár</td><td>Bekerülési érték × szorzó</td><td class="numeric">{{ number_format((float) ($inputs['unrounded_price'] ?? 0), 4, ',', ' ') }} HUF</td></tr>
                <tr><td>Kerekítés</td><td>{{ $rounding['name'] ?? '-' }} · {{ $mode }} · {{ $rounding['round_to'] ?? ('Tizedes: ' . ($rounding['decimal_places'] ?? '-')) }}</td><td class="numeric">{{ number_format((float) ($rounding['rounded_before_adjustment'] ?? 0), 4, ',', ' ') }} HUF</td></tr>
                <tr><td>Kerekítés utáni korrekció</td><td>Hozzáadás / levonás</td><td class="numeric">{{ number_format((float) ($rounding['adjustment'] ?? 0), 4, ',', ' ') }} HUF</td></tr>
                <tr class="retail-total"><td>Kalkulált bruttó kisker ár</td><td>Kerekített érték + korrekció</td><td class="numeric">{{ number_format((float) $record->calculated_price, 4, ',', ' ') }} HUF</td></tr>
                <tr><td>Manuális ár</td><td>Felhasználói eltérés</td><td class="numeric">{{ $record->manual_price === null ? '-' : number_format((float) $record->manual_price, 4, ',', ' ') . ' HUF' }}</td></tr>
                <tr><td>Érvényes kisker szorzó</td><td>Végleges, kerekítés utáni ár ÷ bekerülési érték</td><td class="numeric">{{ $record->effective_multiplier === null ? '-' : rtrim(rtrim(number_format($record->effective_multiplier, 6, ',', ''), '0'), ',') }}</td></tr>
                <tr><td>Cél-beszerzési ár</td><td>Az eredeti beszerzési ár arányos csökkentése a kalkulációs szorzó eléréséhez, felfelé nem módosítva</td><td class="numeric" @if ($record->needsPurchasePriceNegotiation()) style="color: rgb(220 38 38)" @endif>{{ $record->target_purchase_price === null ? '-' : rtrim(rtrim(number_format((float) $record->target_purchase_price, 4, ',', ' '), '0'), ',') . ' ' . ($record->purchaseCurrency?->code ?? '') }}</td></tr>
                <tr><td>Korábban jóváhagyott, megtartott ár</td><td>Újraszámításkor megőrizve</td><td class="numeric">{{ $record->retained_price === null ? '-' : number_format((float) $record->retained_price, 4, ',', ' ') . ' HUF' }}</td></tr>
                <tr class="retail-total"><td>Végleges bruttó kisker ár</td><td>Manuális → megtartott → kalkulált</td><td class="numeric">{{ number_format((float) $record->final_price, 4, ',', ' ') }} HUF</td></tr>
            </tbody>
        </table>
    </div>
    @if (isset($snapshot['approval']))
        @php($approval = $snapshot['approval'])
        <div class="retail-details-wrap mt-4">
            <table>
                <caption class="mb-2 text-left">Legutóbbi átnézés / jóváhagyás · {{ $approval['approved_at'] ?? '-' }}</caption>
                <thead><tr><th>Jóváhagyáskori bekerülési HUF</th><th>Kisker szorzó</th><th>Kalkulált HUF</th><th>Jóváhagyott végleges HUF</th></tr></thead>
                <tbody><tr>
                    <td class="numeric">{{ number_format((float) ($approval['inputs']['base_price'] ?? 0), 4, ',', ' ') }}</td>
                    <td class="numeric">{{ rtrim(rtrim(number_format((float) ($approval['inputs']['multiplier'] ?? 0), 6, ',', ''), '0'), ',') }}</td>
                    <td class="numeric">{{ number_format((float) ($approval['calculated_price'] ?? 0), 4, ',', ' ') }}</td>
                    <td class="numeric retail-total">{{ number_format((float) ($approval['price'] ?? 0), 4, ',', ' ') }}</td>
                </tr></tbody>
            </table>
        </div>
    @endif
    <details class="mt-4"><summary>Kalkuláció mentett bemenetei és szabályai</summary><pre class="mt-2 max-h-96 overflow-auto text-xs">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
</div>
