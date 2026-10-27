<x-filament-panels::page>
    @include('filament.pricing.column-width-actions')

    <div
        data-pricing-resizable-columns
        data-pricing-fit-query="{{ $this->pricingColumnFitQueryKey() }}"
        data-pricing-storage-key="{{ $this->pricingColumnStorageKey() }}"
        data-pricing-server-widths="{{ json_encode($this->pricingColumnWidths) }}"
    >
        {{ $this->content }}
    </div>

    @include('filament.pricing.resizable-columns-styles')
    @include('filament.pricing.resizable-columns-scripts')
</x-filament-panels::page>
