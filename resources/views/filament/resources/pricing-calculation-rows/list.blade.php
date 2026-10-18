<x-filament-panels::page>
    <div data-pricing-operations-grid data-pricing-cost-grid>
        {{ $this->table }}
    </div>
    @include('filament.pricing.cost-grid-interactions')
</x-filament-panels::page>
