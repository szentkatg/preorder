@php
    $snapshot = $record?->calculation_snapshot ?? [];
    $format = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 6, ',', ' '), '0'), ',');
@endphp
<div class="pricing-distributor-details">
    <style>
        .pricing-distributor-details { overflow-x:auto; }
        .pricing-distributor-details table { width:100%;min-width:650px;border-collapse:collapse; }
        .pricing-distributor-details th,.pricing-distributor-details td { border:1px solid #9ca3af;padding:.5rem .75rem;text-align:left; }
        .pricing-distributor-details th { background:#f3f4f6;color:#111827; }
        .dark .pricing-distributor-details th { background:#1f2937;color:#f3f4f6; }
        .pricing-distributor-details .numeric { text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap; }
    </style>
    @if ($record)
        <p>{{ $record->product?->model_code }} · {{ $record->priceList?->code }} · {{ $snapshot['calculated_at'] ?? '-' }}</p>
        @if ($record->requires_review) <p>Felülvizsgálandó. A korábbi végleges disztribútori ár megmaradt. Előbb legyen aktuális a nagyker forrás, majd számítsd újra a disztribútori árat.</p> @endif
        <table>
            <thead><tr><th>Lépés / adat</th><th>Szabály</th><th class="numeric">Érték</th></tr></thead>
            <tbody>
                <tr><td>Nettó nagyker forrás</td><td>{{ $snapshot['source_wholesale_price_list']['code'] ?? '-' }} · sor #{{ $record->source_wholesale_row_id }}</td><td class="numeric">{{ $format($record->base_price) }} EUR</td></tr>
                <tr><td>Disztribútori kedvezmény</td><td>{{ $snapshot['distributor_parameter']['scope_type'] ?? '-' }} · paraméter #{{ $snapshot['distributor_parameter']['id'] ?? '-' }}</td><td class="numeric">{{ $format($record->distributor_discount_percent) }}%</td></tr>
                <tr><td>Kerekítés előtti nettó disztribútori ár</td><td>Nettó nagyker × (1 − kedvezmény% / 100)</td><td class="numeric">{{ $format($record->unrounded_price) }} EUR</td></tr>
                <tr><td>Saját kerekítés</td><td>{{ $snapshot['rounding']['name'] ?? '-' }} · {{ $snapshot['rounding']['mode'] ?? '-' }}</td><td class="numeric">Lépés: {{ $snapshot['rounding']['round_to'] ?? '-' }} · tizedes: {{ $snapshot['rounding']['decimal_places'] ?? '-' }} · korrekció: {{ $format($snapshot['rounding']['adjustment'] ?? null) }}</td></tr>
                <tr><td>Kalkulált nettó disztribútori ár</td><td>Kerekítés és korrekció után</td><td class="numeric">{{ $format($record->calculated_price) }} EUR</td></tr>
                <tr><td>Manuális / korábbi megtartott ár</td><td>Csak a disztribútori árszintre vonatkozik</td><td class="numeric">{{ $format($record->manual_price) }} / {{ $format($record->retained_price) }} EUR</td></tr>
                <tr><td><strong>Végleges nettó disztribútori ár</strong></td><td>Manuális → megtartott → kalkulált</td><td class="numeric"><strong>{{ $format($record->final_price) }} EUR</strong></td></tr>
                <tr><td>Aktuális összehasonlító nettó/nettó szorzó</td><td>A táblázat nagyker sorának bruttó kisker ára / (1 + {{ $format($vat) }}% ÁFA) / végleges disztribútori ár. Az ÁFA személyes beállítás, nem árképzési bemenet.</td><td class="numeric">{{ $format($vat !== null && (float) $record->final_price > 0 && (float) $source->base_price > 0 ? (float) $source->base_price / (1 + $vat / 100) / (float) $record->final_price : null) }}</td></tr>
            </tbody>
        </table>
        @if (isset($snapshot['approval']))
            <p>Legutóbbi disztribútori átnézés/jóváhagyás: {{ $snapshot['approval']['approved_at'] ?? '-' }} · felhasználó #{{ $snapshot['approval']['user_id'] ?? '-' }} · nettó ár: {{ $format($snapshot['approval']['price'] ?? null) }} EUR · akkori nagyker: {{ $format($snapshot['approval']['inputs']['base_price'] ?? null) }} EUR · kedvezmény: {{ $format($snapshot['approval']['inputs']['distributor_discount_percent'] ?? null) }}%</p>
        @endif
    @else
        <p>Nincs disztribútori kalkuláció a kiválasztott árlistához.</p>
    @endif
</div>
