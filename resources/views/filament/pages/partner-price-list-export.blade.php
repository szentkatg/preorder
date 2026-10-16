<x-filament-panels::page>
    <form wire:submit="loadProducts" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-funnel" wire:loading.attr="disabled">Terméklista betöltése</x-filament::button>
    </form>
    <p class="text-sm">A fájl a kiválasztott szezon mentett árlistatételeiből készül. Minden betöltött termék alapból kijelölt, a lapozástól függetlenül.
        A hiányzó ár/adat „-” jelöléssel jelenik meg, nem nullaként. A fájl nyelve nem változtatja meg az admin felület nyelvét.</p>
    @if ($appliedFilters)
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.75rem">
            <x-filament::button wire:click="selectAllProducts" color="gray" wire:loading.attr="disabled">Mindent kijelöl</x-filament::button>
            <x-filament::button wire:click="clearProductSelection" color="gray" wire:loading.attr="disabled">Kijelölés visszavonása</x-filament::button>
            <span x-data x-text="'Kijelölve: ' + $wire.selectedTableRecords.length + ' termék.'">Kijelölve: {{ count($selectedTableRecords) }} termék.</span>
            <x-filament::button wire:click="exportPriceList" icon="heroicon-o-document-arrow-down" wire:loading.attr="disabled">Árlista készítése ({{ strtoupper($appliedFilters['format']) }})</x-filament::button>
        </div>
        @error('selection') <p role="alert" style="color:#dc2626">{{ $message }}</p> @enderror
        {{ $this->table }}
    @endif
</x-filament-panels::page>
