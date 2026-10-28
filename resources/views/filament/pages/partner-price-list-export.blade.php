<x-filament-panels::page>
    <style>
        .partner-price-list-workspace { display: grid; gap: .75rem; min-width: 0; }
        .partner-price-list-settings { border: 1px solid #9ca3af40; border-radius: .75rem; min-width: 0; }
        .partner-price-list-settings-toggle { display: flex; align-items: center; justify-content: space-between; gap: .75rem; width: 100%; padding: .625rem .875rem; font-size: .875rem; font-weight: 600; text-align: left; cursor: pointer; }
        .partner-price-list-settings-form { padding: 0 .875rem .75rem; display: grid; gap: .75rem; }
        .partner-price-list-settings-form .fi-sc { row-gap: .75rem; }
        .partner-price-list-product-filter .fi-select-input-value-badges-ctn { max-height: 3.5rem; overflow-y: auto; overflow-x: hidden; }
        .partner-price-list-summary { display: flex; flex-wrap: wrap; gap: .25rem .75rem; padding: 0 .875rem .625rem; font-size: .75rem; }
        .partner-price-list-summary strong { font-weight: 600; }
        .partner-price-list-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .partner-price-list-help { font-size: .75rem; }
        .partner-price-list-help summary { cursor: pointer; }
        .partner-price-list-help p { margin-top: .375rem; max-width: 85rem; }
    </style>
    <div class="partner-price-list-workspace"
        x-data="{ settingsOpen: $wire.entangle('settingsOpen') }"
        x-init="$watch('settingsOpen', open => $nextTick(() => {
            if (! open &amp;&amp; $refs.settingsForm.contains(document.activeElement)) $refs.settingsToggle.focus();
            window.dispatchEvent(new Event('resize'));
        }))">
        <section class="partner-price-list-settings" aria-label="Árlista beállításai">
            <button type="button" class="partner-price-list-settings-toggle" x-ref="settingsToggle"
                x-on:click="settingsOpen = ! settingsOpen" x-bind:aria-expanded="settingsOpen" aria-expanded="{{ $settingsOpen ? 'true' : 'false' }}"
                aria-controls="partner-price-list-settings-form">
                <span x-text="settingsOpen ? 'Árlista beállításai' : 'Beállítások módosítása'">Árlista beállításai</span>
                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" x-bind:class="{ 'rotate-180': settingsOpen }" />
            </button>
            @if ($appliedFilters)
                <dl class="partner-price-list-summary" aria-label="Betöltött lista feltételei">
                    @foreach ($this->appliedSummary as $label => $value)
                        <div><dt style="display:inline">{{ $label }}:</dt> <dd style="display:inline"><strong>{{ $value }}</strong></dd></div>
                    @endforeach
                </dl>
            @endif
            <form id="partner-price-list-settings-form" wire:submit="loadProducts"
                class="partner-price-list-settings-form" x-ref="settingsForm" x-show="settingsOpen" x-cloak>
                {{ $this->form }}
                <div class="partner-price-list-toolbar">
                    <x-filament::button type="submit" size="sm" icon="heroicon-o-funnel" wire:loading.attr="disabled">Terméklista betöltése</x-filament::button>
                    <span style="font-size:.75rem">Több szűrőérték választható. Üres szűrő: mindegyik.</span>
                </div>
            </form>
        </section>
        @if ($appliedFilters)
            <div class="partner-price-list-toolbar">
                <x-filament::button wire:click="selectAllProducts" size="sm" color="gray" wire:loading.attr="disabled">Mindent kijelöl</x-filament::button>
                <x-filament::button wire:click="clearProductSelection" size="sm" color="gray" wire:loading.attr="disabled">Kijelölés visszavonása</x-filament::button>
                <span style="font-size:.875rem" x-text="'Kijelölve: ' + $wire.selectedTableRecords.length + ' termék.'">Kijelölve: {{ count($selectedTableRecords) }} termék.</span>
                <x-filament::button wire:click="exportPriceList" size="sm" icon="heroicon-o-document-arrow-down" wire:loading.attr="disabled">Árlista készítése ({{ strtoupper($appliedFilters['format']) }})</x-filament::button>
            </div>
            @error('selection') <p role="alert" style="color:#dc2626">{{ $message }}</p> @enderror
            {{ $this->table }}
        @endif
        <details class="partner-price-list-help">
            <summary>Tudnivalók az exportálásról</summary>
            <p>A fájl a kiválasztott szezon mentett árlistatételeiből készül. Minden betöltött termék alapból kijelölt, a lapozástól függetlenül.
                A hiányzó ár/adat „-” jelöléssel jelenik meg, nem nullaként. A fájl nyelve nem változtatja meg az admin felület nyelvét.</p>
        </details>
    </div>
</x-filament-panels::page>
