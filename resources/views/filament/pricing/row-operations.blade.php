@php($operations = $getLivewire()->pricingRowOperations($getRecord()))
@if ($operations)
    <button type="button" data-pricing-operations data-pricing-record-id="{{ $getRecord()->id }}"
        data-pricing-menu="{{ json_encode($operations, JSON_UNESCAPED_UNICODE) }}" aria-label="Műveletek"
        aria-haspopup="menu" aria-expanded="false" title="Műveletek"
        style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:1.5rem;border-radius:.25rem">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
    </button>
@endif
