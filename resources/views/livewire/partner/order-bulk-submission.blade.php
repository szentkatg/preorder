<div>
    @error('selectedSummaryOrderIds') <p role="alert" class="rounded bg-red-100 p-3 text-red-900">{{ $message }}</p> @enderror
    @include('livewire.partner.partials.bulk-order-submission')
</div>
