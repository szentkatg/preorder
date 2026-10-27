<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="erp-formula-builder" data-erp-formula-builder
        x-data="{
            tokens: $wire.$entangle(@js($getStatePath())),
            lists: @js($formulaLists), currencies: @js($formulaCurrencies),
            kind: 'price_list', list: '', currency: '', number: '',
            init() { this.tokens ??= [] },
            append(type, value = null) {
                if (this.tokens.length >= 128) return;
                this.tokens = [...this.tokens, value === null ? { type } : { type, value }];
            },
            canAdd() {
                return this.tokens.length < 128 &amp;&amp; (this.kind === 'price_list' ? !!this.list
                    : this.kind === 'exchange_rate' ? !!this.currency : /^[+-]?(?:\d{1,12}(?:[.,]\d{1,8})?|[.,]\d{1,8})$/.test(this.number.trim()));
            },
            addValue() {
                if (!this.canAdd()) return;
                this.append(this.kind, this.kind === 'price_list' ? this.list : this.kind === 'exchange_rate' ? this.currency : this.number.trim());
            },
            label(token) {
                if (token.type === 'price_list') return this.lists[token.value] || 'Ismeretlen árlista';
                if (token.type === 'exchange_rate') return 'Árfolyam: ' + (this.currencies[token.value] || 'Ismeretlen pénznem');
                return token.type === 'number' ? token.value : ({ '*': '×', '/': '÷' }[token.type] || token.type);
            },
            remove(index) { this.tokens = this.tokens.filter((_, i) => i !== index) },
            move(index, delta) {
                const target = index + delta;
                if (target &lt; 0 || target >= this.tokens.length) return;
                const next = [...this.tokens]; [next[index], next[target]] = [next[target], next[index]]; this.tokens = next;
            }
        }">
        <p class="erp-formula-help">Árfolyam = 1 egység deviza HUF-értéke, az árprojekt szezonjából. Példa EUR → CZK: EUR-ár × EUR-árfolyam ÷ CZK-árfolyam.</p>
        <div class="erp-formula-inputs">
            <label>Építőelem
                <select x-model="kind"><option value="price_list">Publikált árlista</option><option value="exchange_rate">Szezonárfolyam</option><option value="number">Fix szám</option></select>
            </label>
            <label x-show="kind === 'price_list'">Árlista
                <select x-model="list"><option value="">Válassz árlistát…</option><template x-for="[id, label] in Object.entries(lists)" :key="id"><option :value="id" x-text="label"></option></template></select>
            </label>
            <label x-show="kind === 'exchange_rate'" x-cloak>Pénznem és szezonárfolyam
                <select x-model="currency"><option value="">Válassz pénznemet…</option><template x-for="[id, label] in Object.entries(currencies)" :key="id"><option :value="id" x-text="label"></option></template></select>
            </label>
            <label x-show="kind === 'number'" x-cloak>Fix szám
                <input type="text" inputmode="decimal" x-model="number" placeholder="Például 25 vagy 1,27" maxlength="24" x-on:keydown.enter.prevent="addValue()">
            </label>
            <button type="button" x-on:click="addValue()" :disabled="!canAdd()">Érték hozzáadása</button>
        </div>
        <div class="erp-formula-operators" role="group" aria-label="Képlet műveletei">
            <template x-for="operator in ['+', '-', '*', '/', '(', ')']" :key="operator">
                <button type="button" x-on:click="append(operator)" :disabled="tokens.length >= 128"
                    :aria-label="({'+':'Összeadás','-':'Kivonás','*':'Szorzás','/':'Osztás','(':'Nyitó zárójel',')':'Záró zárójel'})[operator]"
                    x-text="({'*':'×','/':'÷'})[operator] || operator"></button>
            </template>
            <button type="button" x-on:click="tokens = []" :disabled="tokens.length === 0">Képlet ürítése</button>
        </div>
        <ol class="erp-formula-tokens" aria-label="Képlet építőelemei">
            <template x-for="(token, index) in tokens" :key="index">
                <li>
                    <span x-text="label(token)"></span>
                    <button type="button" x-on:click="move(index, -1)" :disabled="index === 0" aria-label="Építőelem balra">←</button>
                    <button type="button" x-on:click="move(index, 1)" :disabled="index === tokens.length - 1" aria-label="Építőelem jobbra">→</button>
                    <button type="button" x-on:click="remove(index)" aria-label="Építőelem törlése">×</button>
                </li>
            </template>
        </ol>
        <p class="erp-formula-preview"><strong>Képlet:</strong> <span x-text="tokens.length ? tokens.map(token => label(token)).join(' ') : 'Még nincs megadva.'"></span></p>
        <p class="erp-formula-help">Az építőelemek sorrendje a nyilakkal módosítható. Szokásos műveleti sorrend és zárójelezés; legfeljebb 128 elem. Fix számnál pont és vessző is használható. Az Eredeti és Lista ár oszloppár ugyanazt az eredményt kapja. Hibás képlet, hiányzó forrásár/árfolyam vagy nullával osztás esetén az export nem készül el.</p>
    </div>
    @once
        <style>
            .erp-formula-builder { padding:.75rem;border:1px solid #94a3b8;border-radius:.5rem;min-width:0 }
            .erp-formula-help { font-size:.8125rem;line-height:1.4;opacity:.85;margin:.25rem 0 .75rem }
            .erp-formula-inputs { display:flex;flex-wrap:wrap;align-items:end;gap:.5rem }
            .erp-formula-inputs label { display:grid;gap:.25rem;min-width:0;font-size:.8125rem }
            .erp-formula-builder :is(input,select,button) { border:1px solid #94a3b8;border-radius:.375rem;background:transparent;color:inherit;padding:.3rem .5rem;font:inherit }
            .erp-formula-builder select { max-width:min(100%,28rem) }
            .erp-formula-builder input { width:12rem;max-width:100% }
            .erp-formula-builder button:disabled { opacity:.4 }
            .erp-formula-builder button:focus-visible { outline:2px solid #3b82f6;outline-offset:2px }
            .erp-formula-operators { display:flex;flex-wrap:wrap;gap:.35rem;margin:.75rem 0 }
            .erp-formula-tokens { display:flex;flex-wrap:wrap;gap:.4rem;list-style:none;padding:0;margin:.5rem 0 }
            .erp-formula-tokens li { display:flex;align-items:center;gap:.25rem;padding:.2rem .35rem;background:rgb(59 130 246 / .1);border:1px solid #94a3b8;border-radius:.375rem;max-width:100% }
            .erp-formula-tokens li span { overflow-wrap:anywhere }
            .erp-formula-tokens button { padding:.05rem .2rem;font-size:.75rem }
            .erp-formula-preview { padding:.5rem;background:rgb(100 116 139 / .12);border-radius:.375rem;overflow-wrap:anywhere }
            .dark .erp-formula-builder select option { background:#111827;color:#e5e7eb }
        </style>
    @endonce
</x-dynamic-component>
