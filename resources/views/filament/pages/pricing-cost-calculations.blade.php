<x-filament-panels::page>
    <div class="space-y-4">
        @if (method_exists($this, 'selectWholesaleCurrency'))
            <div class="pricing-project-selection" role="group" aria-label="Nagyker árak pénzneme">
                @foreach (\App\Services\Pricing\WholesaleCalculator::CURRENCIES as $currencyCode)
                    <button type="button" wire:click="selectWholesaleCurrency('{{ $currencyCode }}')" wire:loading.attr="disabled"
                        aria-pressed="{{ $this->retailCurrencyCode() === $currencyCode ? 'true' : 'false' }}"
                        style="padding:.375rem .75rem;border:1px solid currentColor;border-radius:.5rem;{{ $this->retailCurrencyCode() === $currencyCode ? 'font-weight:700;text-decoration:underline' : '' }}">
                        {{ $currencyCode }}
                    </button>
                @endforeach
            </div>
        @endif
        @if (method_exists($this, 'selectRetailCurrency'))
            <div class="pricing-project-selection" role="group" aria-label="Kisker árak pénzneme">
                @foreach (\App\Services\Pricing\ForeignRetailCalculator::CURRENCIES as $currencyCode)
                    <button type="button" wire:click="selectRetailCurrency('{{ $currencyCode }}')" wire:loading.attr="disabled"
                        aria-pressed="{{ $this->retailCurrencyCode() === $currencyCode ? 'true' : 'false' }}"
                        style="padding: .375rem .75rem; border: 1px solid currentColor; border-radius: .5rem; {{ $this->retailCurrencyCode() === $currencyCode ? 'font-weight:700; text-decoration:underline' : '' }}">
                        {{ $currencyCode }}
                    </button>
                @endforeach
            </div>
        @endif
        <div class="pricing-project-selection">
            <label>
                <span>Árprojekt</span>
                <select wire:change="selectPricingProject($event.target.value)" wire:loading.attr="disabled">
                    <option value="" @selected($this->pricingProjectId === null)>Válassz árprojektet…</option>
                    @foreach ($this->pricingProjectOptions as $projectOption)
                        <option value="{{ $projectOption->id }}" @selected($this->pricingProjectId === (int) $projectOption->id)>
                            {{ $projectOption->name }} — {{ $projectOption->season?->name }}
                            ({{ $projectOption->status === 'closed' ? 'Lezárt' : ($projectOption->status === 'draft' ? 'Tervezet' : 'Aktív') }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="pricing-archive-toggle">
                <input type="checkbox" @checked($this->pricingArchive)
                    wire:change="setPricingArchive($event.target.checked)" wire:loading.attr="disabled">
                Archív megtekintés
            </label>
            @if ($this->pricingArchive)
                <span>Lezárt árprojektek — csak olvasható, exportálható nézet.</span>
            @elseif (! $this->pricingSelectedProject)
                <span>Válassz egy nem lezárt árprojektet. Kiválasztás nélkül nem töltünk be tételeket.</span>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            {{ $this->pricingWorkspaceDescription() }}
            A jobb felső oszlopkezelővel ki/be kapcsolhatók és átrendezhetők az oszlopok,
            az oszlopszélességek a fejléc jobb szélén húzással állíthatók,
            @if (method_exists($this, 'openCostDetails') || method_exists($this, 'openPricingDetails'))
                a színezett cellák dupla kattintással szerkeszthetők, a sor többi részén dupla kattintás mutatja a részleteket.
            @else
                a soroknál pedig a <span class="font-semibold">Részletek</span> gomb mutatja a teljes számítási hátteret.
            @endif

            @include('filament.pricing.column-width-actions')
        </div>

        @if (method_exists($this, 'wholesaleVatPercent'))
            <div class="pricing-project-selection" data-wholesale-vat-panel>
                @if ($this->retailCurrencyCode() === 'EUR')
                    <label>
                        <span>EUR kisker ár nettósítása — ÁFA %</span>
                        <input type="text" inputmode="decimal" value="{{ $this->eurVatPercent }}" placeholder="ÁFA % megadása"
                            wire:change="setEurVatPercent($event.target.value)" wire:loading.attr="disabled"
                            aria-label="EUR kisker ár ÁFA százalék" style="width:9rem;border:1px solid currentColor;border-radius:.375rem;padding:.25rem .5rem;background:transparent">
                    </label>
                    @error('eurVatPercent') <span role="alert">{{ $message }}</span> @enderror
                    @if (method_exists($this, 'selectDistributorPriceList'))
                    <label data-distributor-price-list-panel>
                        <span>Disztribútori árlista</span>
                        <select wire:change="selectDistributorPriceList($event.target.value)" wire:loading.attr="disabled">
                            <option value="" @selected($this->distributorPriceListId === null)>Válassz disztribútori árlistát…</option>
                            @foreach ($this->distributorPriceLists as $distributorList)
                                <option value="{{ $distributorList->id }}" @selected($this->distributorPriceListId === (int) $distributorList->id)>
                                    {{ $distributorList->code }} ← {{ $distributorList->sourceWholesalePriceList?->code }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    @if ($this->selectedDistributorPriceList)
                        <span>Forrás: {{ $this->selectedDistributorPriceList->sourceWholesalePriceList?->code }} — csak ennek a nagyker árlistának a sorai. ÁFA csak az összehasonlításhoz.</span>
                    @else
                        <span>Válassz disztribútori árlistát, vagy hozz létre újat az Árlisták törzsben. ÁFA csak az összehasonlításhoz; felhasználónként megjegyezzük.</span>
                    @endif
                    @else
                        <span>Az ÁFA csak az összehasonlításhoz szolgál; felhasználónként megjegyezzük.</span>
                    @endif
                @else
                    <span>HUF kisker ár nettósítása: 27% ÁFA — csak a nettó/nettó szorzóhoz.</span>
                @endif
            </div>
        @endif

        @include('filament.pricing.cost-adjustment-panel')

        <div
            data-pricing-resizable-columns
            data-pricing-fit-query="{{ $this->pricingColumnFitQueryKey() }}"
            data-pricing-operations-grid
            @if (method_exists($this, 'openCostDetails')) data-pricing-cost-grid @endif
            @if (method_exists($this, 'openPricingDetails')) data-pricing-price-grid @endif
            data-pricing-storage-key="{{ $this->pricingColumnStorageKey() }}"
            data-pricing-server-widths="{{ json_encode($this->pricingColumnWidths) }}"
        >
            {{ $this->table }}
        </div>
    </div>

    @include('filament.pricing.cost-grid-interactions')

    @include('filament.pricing.resizable-columns-styles')

    <style>
        .pricing-project-selection {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid rgb(209 213 219);
            border-radius: 0.75rem;
            font-size: 0.875rem;
        }

        .pricing-project-selection label {
            display: flex;
            min-width: 0;
            max-width: 100%;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .pricing-project-selection select {
            max-width: 100%;
            min-width: min(18rem, 100%);
            border: 1px solid rgb(156 163 175);
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            background-color: white;
            color: rgb(17 24 39);
        }

        .dark .pricing-project-selection {
            border-color: rgb(55 65 81);
        }

        .dark .pricing-project-selection select {
            background-color: rgb(17 24 39);
            color: rgb(229 231 235);
        }

        .pricing-inline-parameter-panel {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: end;
            justify-content: space-between;
            padding: 1rem;
            color: rgb(113 63 18);
            background: rgb(255 251 235);
            border: 1px solid rgb(252 211 77);
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }

        .pricing-inline-parameter-text {
            min-width: 18rem;
        }

        .pricing-inline-parameter-title {
            font-weight: 700;
        }

        .pricing-inline-parameter-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            margin-top: 0.35rem;
            font-size: 0.875rem;
        }

        .pricing-inline-parameter-pill {
            font-weight: 700;
        }

        .pricing-inline-parameter-impact {
            font-weight: 700;
            color: rgb(146 64 14);
        }

        .pricing-inline-parameter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: end;
        }

        .pricing-inline-parameter-field {
            display: grid;
            gap: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .pricing-inline-parameter-field select {
            min-width: 16rem;
            padding: 0.45rem 0.65rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgb(17 24 39);
            background: white;
            border: 1px solid rgb(217 119 6);
            border-radius: 0.5rem;
        }

        .pricing-inline-parameter-save,
        .pricing-inline-parameter-cancel {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.5rem;
        }

        .pricing-inline-parameter-save {
            color: white;
            background: rgb(37 99 235);
        }

        .pricing-inline-parameter-save:disabled,
        .pricing-inline-parameter-cancel:disabled {
            opacity: 0.6;
        }

        .pricing-inline-parameter-cancel {
            color: rgb(55 65 81);
            background: white;
            border: 1px solid rgb(209 213 219);
        }

        .dark .pricing-inline-parameter-panel {
            color: rgb(254 243 199);
            background: rgb(69 26 3 / 0.45);
            border-color: rgb(180 83 9);
        }

        .dark .pricing-inline-parameter-field select,
        .dark .pricing-inline-parameter-cancel {
            color: rgb(229 231 235);
            background: rgb(17 24 39);
            border-color: rgb(75 85 99);
        }

        .dark .pricing-inline-parameter-impact {
            color: rgb(252 211 77);
        }
    </style>

    @include('filament.pricing.resizable-columns-scripts')
</x-filament-panels::page>
