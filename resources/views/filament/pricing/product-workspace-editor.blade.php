<div class="product-pricing-editor">
    <p>Előnézet: bevitel közben nincs végleges mentés. A százalékok és szorzók csak az adott modellre érvényesek.
        A beszerzési ár módosítása törzsadatot változtat. Üres manuális ár = új kalkulált ár használata.</p>
    <h3>Beszerzési árak</h3>
    <div class="product-pricing-fields">
        @foreach ($page->pricingEditorSettings['purchases'] ?? [] as $purchase)
            <label wire:key="purchase-{{ $purchase['id'] }}">
                <span>{{ $purchase['supplier'] }} · {{ $purchase['currency'] }} · {{ $purchase['color'] ?: 'Modell' }}</span>
                <input type="text" inputmode="decimal" @disabled(! $purchase['editable']) wire:model.live.debounce.600ms="pricingEditor.purchase_prices.{{ $purchase['id'] }}">
            </label>
        @endforeach
    </div>
    <p>Szezonárfolyamok (közös törzsadat, itt csak olvasható):
        @foreach ($page->pricingEditorSettings['exchange_rates'] ?? [] as $rate)
            <span style="display:inline-block;margin-right:1rem">1 {{ $rate['currency'] }} = {{ rtrim(rtrim(number_format((float) $rate['rate'], 4, ',', ' '), '0'), ',') }} HUF</span>
        @endforeach
        A kerekítés az árlistához beállított szabályt követi.
    </p>
    <h3>Modellszintű kalkulációs paraméterek</h3>
    <div class="product-pricing-fields">
        @foreach ($page->pricingEditorSettings['fields'] ?? [] as $key => $field)
            <label wire:key="parameter-{{ $key }}"><span>{{ $field['label'] }}</span>
                <input type="text" inputmode="decimal" @disabled(! $field['editable']) wire:model.live.debounce.600ms="pricingEditor.parameters.{{ $key }}">
            </label>
        @endforeach
        @foreach ($page->pricingEditorSettings['foreign_lists'] ?? [] as $id => $code)
            <label wire:key="source-{{ $id }}"><span>{{ $code }} HUF forrása</span>
                <select wire:model.live="pricingEditor.sources.{{ $id }}">
                    <option value="">Válassz…</option>
                    @foreach ($page->pricingEditorSettings['huf_sources'] as $sourceId => $sourceCode)
                        <option value="{{ $sourceId }}">{{ $sourceCode }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>
    <p wire:loading role="status">A függő árak újraszámítása folyamatban…</p>
    @if ($page->pricingEditorError)
        <p role="alert" style="color:#ef4444">{{ $page->pricingEditorError }} Az alábbi árak az utolsó sikeres előnézetet mutatják; a hiba javításáig nem menthetők.</p>
    @endif
    @foreach ($page->pricingEditorPreview['costs'] ?? [] as $cost)
        <p><strong>{{ $cost['color'] }} bekerülési értéke: {{ number_format((float) $cost['price'], 2, ',', ' ') }} HUF</strong></p>
        @if (isset($cost['purchase']))
            <p>{{ $cost['supplier'] }} · Beszerzés: {{ number_format((float) $cost['purchase'], 4, ',', ' ') }} {{ $cost['currency'] }}
                · Árfolyam: {{ number_format((float) $cost['rate'], 4, ',', ' ') }}
                · Tényleges szállítás: {{ number_format((float) $cost['shipping'], 2, ',', ' ') }}%
                · Tényleges vám: {{ number_format((float) $cost['customs'], 2, ',', ' ') }}%</p>
        @endif
    @endforeach
    <div style="overflow-x:auto">
        <table class="product-pricing-preview">
            <thead><tr><th>Árlista</th><th>Szín</th><th>Jelenlegi mentett ár</th><th>Új kalkulált ár</th><th>Manuális ár</th><th>Mentendő végleges ár</th></tr></thead>
            <tbody>
                @foreach ($page->pricingEditorPreview['prices'] ?? [] as $price)
                    <tr wire:key="preview-{{ $price['key'] }}"><td>{{ $price['list'] }} ({{ $price['currency'] }})</td><td>{{ $price['color'] }}</td>
                        <td>{{ isset($page->pricingEditorSettings['original_prices'][$price['key']]) ? number_format((float) $page->pricingEditorSettings['original_prices'][$price['key']], 2, ',', ' ') : '—' }}</td>
                        <td>{{ number_format((float) $price['calculated'], 2, ',', ' ') }}</td>
                        <td><input aria-label="{{ $price['list'] }} {{ $price['color'] }} manuális ár" type="text" inputmode="decimal"
                            wire:model.live.debounce.600ms="pricingEditor.manual.{{ $price['key'] }}" placeholder="Kalkulált ár"></td>
                        <td style="font-weight:700;{{ $price['manual'] !== null ? ((float) $price['final'] < (float) $price['calculated'] ? 'color:#ef4444' : 'color:#16a34a') : '' }}">
                            {{ number_format((float) $price['final'], 2, ',', ' ') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
