<div data-huf-retail-clipboard-result style="display:grid;gap:1rem">
    @if ($result['expired'] ?? false)
        <p role="alert">A részletes eredménylista ideiglenes tárolása lejárt. A már elmentett manuális árak ettől nem változtak.</p>
    @endif
    <p><strong>{{ $result['project'] ?? '' }} — {{ $result['price_list'] ?? '' }}</strong></p>
    <p>
        Betöltve: <strong>{{ $result['updated_models'] ?? 0 }} modell / {{ $result['updated_rows'] ?? 0 }} ársor</strong>.
        Kalkulált árral egyező, teljesen kihagyott modell: <strong>{{ $result['equal'] ?? 0 }}</strong>.
        Nem talált modell vagy kalkuláció: <strong>{{ $result['not_found'] ?? 0 }}</strong>.
        Már meglévő manuális ár miatt változatlan modell: <strong>{{ $result['unchanged'] ?? 0 }}</strong>.
        Egyéb hibás sor: <strong>{{ $result['errors'] ?? 0 }}</strong>.
    </p>
    <p>Az árak nem kaptak automatikus jóváhagyást. A kalkulált árral egyező ársorok és a publikált árak változatlanok maradtak.</p>
    <div style="max-height:60vh;overflow:auto;border:1px solid #6b7280;border-radius:.25rem">
        <table style="width:100%;border-collapse:collapse;text-align:left">
            <thead style="position:sticky;top:0;background:var(--gray-100,#f3f4f6);color:#111827">
                <tr>
                    @foreach (['Vágólapsor', 'Beillesztett modellkód', 'Tisztított modellkód', 'Beillesztett ár', 'Kalkulált ár (HUF)', 'Eredmény'] as $heading)
                        <th style="border:1px solid #6b7280;padding:.35rem .5rem">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($result['entries'] ?? [] as $entry)
                    <tr style="{{ in_array($entry['status'], ['error', 'not_found'], true) ? 'background:rgba(239,68,68,.12)' : ($entry['equal_rows'] ? 'background:rgba(245,158,11,.12)' : '') }}">
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem">{{ $entry['line'] }}</td>
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem">{{ $entry['input_code'] }}</td>
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem">{{ $entry['model_code'] }}</td>
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem;text-align:right">{{ $entry['input_price'] }}</td>
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem;text-align:right">
                            {{ implode(' / ', array_map(fn ($price) => $price === null ? '—' : rtrim(rtrim(number_format((float) $price, 4, ',', ' '), '0'), ','), $entry['calculated_prices'])) }}
                        </td>
                        <td style="border:1px solid #6b7280;padding:.25rem .5rem">{{ $entry['message'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
