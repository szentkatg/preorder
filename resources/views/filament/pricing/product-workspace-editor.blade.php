<div class="product-pricing-editor">
    <p>Előnézet: bevitel közben nincs végleges mentés. A százalékok és szorzók csak az adott modellre érvényesek.
        A beszerzési ár módosítása törzsadatot változtat. Üres manuális ár = új kalkulált ár használata.</p>
    <p wire:loading role="status">A függő árak újraszámítása folyamatban…</p>
    @if ($page->pricingEditorError)
        <p role="alert" style="color:#ef4444">{{ $page->pricingEditorError }} Az alábbi árak az utolsó sikeres előnézetet mutatják; a hiba javításáig nem menthetők.</p>
    @endif
    <div class="product-pricing-tables">
        <section aria-label="Beviteli paraméterek">
            <h3>Beviteli paraméterek</h3>
            <div class="product-pricing-table-scroll">
                <table class="product-pricing-parameters">
                    <thead><tr><th scope="col">Paraméter / forrás</th><th scope="col">Érték</th></tr></thead>
                    <tbody>
                        <tr><th colspan="2" scope="colgroup">Beszerzési árak</th></tr>
                        @foreach ($page->pricingEditorSettings['purchases'] ?? [] as $purchase)
                            <tr wire:key="purchase-{{ $purchase['id'] }}">
                                <th scope="row"><label for="pricing-purchase-{{ $purchase['id'] }}">{{ $purchase['supplier'] }} · {{ $purchase['currency'] }} · {{ $purchase['color'] ?: 'Modell' }}</label></th>
                                <td><input id="pricing-purchase-{{ $purchase['id'] }}" type="text" inputmode="decimal" @disabled(! $purchase['editable'])
                                    wire:model.live.debounce.600ms="pricingEditor.purchase_prices.{{ $purchase['id'] }}"></td>
                            </tr>
                        @endforeach
                        <tr><th colspan="2" scope="colgroup">Modellszintű százalékok és szorzók</th></tr>
                        @foreach ($page->pricingEditorSettings['fields'] ?? [] as $key => $field)
                            <tr wire:key="parameter-{{ $key }}">
                                <th scope="row"><label for="pricing-parameter-{{ $key }}">{{ $field['label'] }}</label></th>
                                <td><input id="pricing-parameter-{{ $key }}" type="text" inputmode="decimal" @disabled(! $field['editable'])
                                    wire:model.live.debounce.600ms="pricingEditor.parameters.{{ $key }}"></td>
                            </tr>
                        @endforeach
                        @foreach ($page->pricingEditorSettings['foreign_lists'] ?? [] as $id => $code)
                            <tr wire:key="source-{{ $id }}">
                                <th scope="row"><label for="pricing-source-{{ $id }}">{{ $code }} HUF forrása</label></th>
                                <td><select id="pricing-source-{{ $id }}" wire:model.live="pricingEditor.sources.{{ $id }}">
                                    <option value="">Válassz…</option>
                                    @foreach ($page->pricingEditorSettings['huf_sources'] as $sourceId => $sourceCode)
                                        <option value="{{ $sourceId }}">{{ $sourceCode }}</option>
                                    @endforeach
                                </select></td>
                            </tr>
                        @endforeach
                        <tr><th colspan="2" scope="colgroup">Szezonárfolyamok – csak olvasható</th></tr>
                        @foreach ($page->pricingEditorSettings['exchange_rates'] ?? [] as $rate)
                            <tr><th scope="row">1 {{ $rate['currency'] }} / HUF</th><td>{{ number_format((float) $rate['rate'], 4, ',', ' ') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p>A kerekítés az árlistához beállított szabályt követi.</p>
        </section>
        <section aria-label="Árak és tényleges szorzók">
            <h3>Árak és tényleges szorzók</h3>
            <p>Bekerülési szorzó = végleges ár HUF-ra váltva / bekerülési érték. Kereskedelmi szorzók: kisker / adott ár, illetve nagyker / disztribútori ár.</p>
            <div class="product-pricing-table-scroll">
                <table class="product-pricing-preview">
                    <thead><tr><th scope="col">Árlista / pénznem</th><th scope="col">Szín</th><th scope="col">Mentett ár</th><th scope="col">Új kalkulált ár</th><th scope="col">Manuális ár</th><th scope="col">Mentendő végleges ár</th><th scope="col">Bekerülési szorzó</th><th scope="col">Kisker / adott ár</th><th scope="col">Nagyker / adott ár</th></tr></thead>
                    <tbody>
                        @foreach ($page->pricingEditorPreview['prices'] ?? [] as $price)
                            <tr wire:key="preview-{{ $price['key'] }}"><th scope="row">{{ $price['list'] }} ({{ $price['currency'] }})</th><td>{{ $price['color'] }}</td>
                                <td>{{ isset($page->pricingEditorSettings['original_prices'][$price['key']]) ? number_format((float) $page->pricingEditorSettings['original_prices'][$price['key']], 2, ',', ' ') : '—' }}</td>
                                <td>{{ number_format((float) $price['calculated'], 2, ',', ' ') }}</td>
                                <td><input aria-label="{{ $price['list'] }} {{ $price['color'] }} manuális ár" type="text" inputmode="decimal"
                                    wire:model.live.debounce.600ms="pricingEditor.manual.{{ $price['key'] }}" placeholder="Kalkulált ár"></td>
                                <td style="font-weight:700;{{ $price['manual'] !== null ? ((float) $price['final'] < (float) $price['calculated'] ? 'color:#ef4444' : 'color:#16a34a') : '' }}">
                                    {{ number_format((float) $price['final'], 2, ',', ' ') }}
                                </td>
                                @foreach (['cost_multiplier', 'retail_multiplier', 'wholesale_multiplier'] as $multiplier)
                                    <td>{{ isset($price[$multiplier]) ? number_format((float) $price[$multiplier], 2, ',', ' ') : '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <h3>A kiválasztott bekerülési érték alapja</h3>
            <div class="product-pricing-table-scroll">
                <table class="product-pricing-preview">
                    <thead><tr><th>Szín</th><th>Beszállító</th><th>Beszerzés</th><th>Árfolyam</th><th>Szállítás %</th><th>Vám %</th><th>Bekerülési érték HUF</th></tr></thead>
                    <tbody>
                        @foreach ($page->pricingEditorPreview['costs'] ?? [] as $cost)
                            <tr><th scope="row">{{ $cost['color'] }}</th><td>{{ $cost['supplier'] ?? '—' }}</td>
                                <td>{{ isset($cost['purchase']) ? number_format((float) $cost['purchase'], 4, ',', ' ').' '.$cost['currency'] : '—' }}</td>
                                @foreach (['rate', 'shipping', 'customs'] as $input)
                                    <td>{{ isset($cost[$input]) ? number_format((float) $cost[$input], $input === 'rate' ? 4 : 2, ',', ' ') : '—' }}</td>
                                @endforeach
                                <td><strong>{{ number_format((float) $cost['price'], 2, ',', ' ') }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
