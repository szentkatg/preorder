<div style="display:grid;gap:2rem">
    @foreach ($details as $detail)
        @php($source = $detail['source'])
        <section wire:key="pricing-bulk-details-{{ $source->id }}">
            <h3 style="font-weight:bold;margin-bottom:.5rem">{{ $source->product?->model_code }} — {{ $source->product?->name_hu }}</h3>
            {{ $detail['view'] }}
        </section>
    @endforeach
</div>
