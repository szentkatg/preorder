<div>
    @include('filament.pricing.retail-calculation-details', ['record' => $record])
    @foreach ($record->outletRows()->with(['priceList.currency', 'pricingRoundingRule'])->get() as $outlet)
        @can('view', $outlet)
            @include('filament.pricing.outlet-calculation-details', ['record' => $outlet])
        @endcan
    @endforeach
</div>
