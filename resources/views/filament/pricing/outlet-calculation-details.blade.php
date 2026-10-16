@php
    $snapshot = $record->calculation_snapshot ?? [];
    $parameter = $snapshot['outlet_parameter'] ?? [];
    $rounding = $snapshot['rounding'] ?? [];
@endphp
<div style="overflow-x:auto">
    <h3 style="margin:1.25rem 0 .5rem;font-weight:700">{{ $record->priceList?->code }} – Outlet kalkuláció ({{ $snapshot['currency'] ?? $record->currency?->code }})</h3>
    <p style="margin-bottom:.5rem">{{ $record->product?->model_code }} · {{ $snapshot['calculated_at'] ?? '-' }}</p>
    <table style="width:100%;border-collapse:collapse">
        <thead><tr><th style="padding:.5rem;border:1px solid #64748b;text-align:left">Lépés / bemeneti adat</th><th style="padding:.5rem;border:1px solid #64748b;text-align:left">Forrás / szabály</th><th style="padding:.5rem;border:1px solid #64748b;text-align:right">Érték</th></tr></thead>
        <tbody>
            @foreach ([
                ['Bruttó kisker alapár', $snapshot['source_retail_price_list']['code'] ?? '-', $record->base_price],
                ['Minimum kedvezmény %', 'Paraméter #'.($parameter['id'] ?? '-').' · '.match($parameter['scope_type'] ?? '') { 'product' => 'Modell', 'item_main_group' => 'Főcsoport', 'supplier_country' => 'Beszállító ország', default => 'Globális' }, $record->outlet_discount_percent],
                ['Kerekítés előtti ár', 'Kisker alapár × (1 − kedvezmény% / 100)', $record->unrounded_price],
                ['Kalkulált outlet ár', ($rounding['name'] ?? '-').' · lépés: '.($rounding['round_to'] ?? '-').' · '.($rounding['mode'] ?? '-').' · korrekció: '.($rounding['adjustment'] ?? 0), $record->calculated_price],
                ['Végleges outlet ár', $record->manual_price !== null ? 'Manuális ár' : ($record->retained_price !== null ? 'Megtartott ár' : 'Kalkulált ár'), $record->final_price],
                ['Tényleges kedvezmény %', '(1 − végleges outlet / kisker alapár) × 100', $record->actual_outlet_discount],
            ] as [$label, $rule, $value])
                <tr><td style="padding:.5rem;border:1px solid #64748b">{{ $label }}</td><td style="padding:.5rem;border:1px solid #64748b">{{ $rule }}</td><td style="padding:.5rem;border:1px solid #64748b;text-align:right;white-space:nowrap;{{ ! $record->outletDiscountIsSufficient() ? 'color:#ef4444' : '' }}">{{ number_format((float) $value, 2, ',', ' ') }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @if (! $record->outletDiscountIsSufficient())
        <p style="color:#ef4444;font-weight:700">A tényleges kedvezmény kisebb a minimumnál. Ez az outlet ár nem hagyható jóvá.</p>
    @endif
    @if ($record->requires_review)
        <p style="font-weight:700">Felülvizsgálandó: a korábbi végleges árat megtartottuk. Az aktuális bemenetekkel számítsd újra az outlet árat, majd dönts az ár megtartásáról vagy módosításáról.</p>
    @endif
</div>
