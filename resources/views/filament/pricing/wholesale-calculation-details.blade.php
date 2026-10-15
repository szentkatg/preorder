@php
    $snapshot = $record->calculation_snapshot ?? [];
    $currency = $record->currency?->code;
    $format = fn ($value) => $value === null ? '-' : rtrim(rtrim(number_format((float) $value, 6, ',', ' '), '0'), ',');
@endphp
<div class="pricing-wholesale-details">
    <style>
        .pricing-wholesale-details { overflow-x:auto; }
        .pricing-wholesale-details table { width:100%;min-width:600px;border-collapse:collapse; }
        .pricing-wholesale-details th,.pricing-wholesale-details td { border:1px solid #9ca3af;padding:.5rem .75rem;text-align:left; }
        .pricing-wholesale-details th { background:#f3f4f6;color:#111827; }
        .dark .pricing-wholesale-details th { background:#1f2937;color:#f3f4f6; }
        .pricing-wholesale-details .numeric { text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap; }
    </style>
    <p>{{ $record->product?->model_code }} · {{ $record->priceList?->code }} · {{ $snapshot['calculated_at'] ?? '-' }}</p>
    @if ($record->requires_review) <p>Felülvizsgálandó. A korábbi végleges ár megmaradt; újraszámítás után dönts az elfogadásáról.</p> @endif
    <table>
        <thead><tr><th>Lépés / adat</th><th>Szabály</th><th class="numeric">Érték</th></tr></thead>
        <tbody>
            <tr><td>Bruttó kisker forrás</td><td>{{ $snapshot['source_retail_price_list']['code'] ?? '-' }} · sor #{{ $record->source_retail_row_id }}</td><td class="numeric">{{ $format($record->base_price) }} {{ $currency }}</td></tr>
            <tr><td>Nagyker szorzó</td><td>{{ $snapshot['wholesale_parameter']['scope_type'] ?? '-' }} · paraméter #{{ $snapshot['wholesale_parameter']['id'] ?? '-' }}</td><td class="numeric">{{ $format($record->multiplier) }}</td></tr>
            <tr><td>Kerekítés előtti nettó nagyker</td><td>Bruttó kisker ÷ nagyker szorzó; nincs külön ÁFA-levonás</td><td class="numeric">{{ $format($record->unrounded_price) }} {{ $currency }}</td></tr>
            <tr><td>Kerekítési szabály</td><td>{{ $snapshot['rounding']['name'] ?? '-' }} · {{ $snapshot['rounding']['mode'] ?? '-' }}</td><td class="numeric">Lépés: {{ $snapshot['rounding']['round_to'] ?? '-' }} · tizedes: {{ $snapshot['rounding']['decimal_places'] ?? '-' }} · korrekció: {{ $format($snapshot['rounding']['adjustment'] ?? null) }}</td></tr>
            <tr><td>Kalkulált nettó nagyker</td><td>Kerekítés és korrekció után</td><td class="numeric">{{ $format($record->calculated_price) }} {{ $currency }}</td></tr>
            <tr><td>Manuális / megtartott ár</td><td>Felhasználói eltérés / korábbi jóváhagyás védelme</td><td class="numeric">{{ $format($record->manual_price) }} / {{ $format($record->retained_price) }}</td></tr>
            <tr><td><strong>Végleges nettó nagyker</strong></td><td>Manuális → megtartott → kalkulált</td><td class="numeric"><strong>{{ $format($record->final_price) }} {{ $currency }}</strong></td></tr>
            <tr><td>Tényleges nagyker szorzó</td><td>Bruttó kisker ÷ végleges nettó nagyker</td><td class="numeric">{{ $format($record->effective_wholesale_multiplier) }}</td></tr>
            <tr><td>Nettó/nettó szorzó</td><td>Kisker nettósítása {{ $format($vat) }}% ÁFA-val — csak aktuális összehasonlítás</td><td class="numeric">{{ $format($vat !== null && $record->effective_wholesale_multiplier !== null ? $record->effective_wholesale_multiplier / (1 + $vat / 100) : null) }}</td></tr>
        </tbody>
    </table>
    @if (isset($snapshot['approval']))
        <p>Legutóbbi átnézés/jóváhagyás: {{ $snapshot['approval']['approved_at'] ?? '-' }} · felhasználó #{{ $snapshot['approval']['user_id'] ?? '-' }} · nettó ár: {{ $format($snapshot['approval']['price'] ?? null) }} {{ $currency }} · akkori bruttó kisker: {{ $format($snapshot['approval']['inputs']['base_price'] ?? null) }} · szorzó: {{ $format($snapshot['approval']['inputs']['multiplier'] ?? null) }}</p>
    @endif
</div>
