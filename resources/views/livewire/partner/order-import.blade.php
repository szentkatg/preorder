<div>
    @if ($showImportModal)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-black/50 p-4"
            x-data x-on:keydown.escape.window="$wire.close()" wire:key="order-import-modal">
            <section role="dialog" aria-modal="true" aria-labelledby="order-import-heading"
                class="max-h-[90vh] w-full max-w-7xl overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between gap-4">
                    <h2 id="order-import-heading" class="text-lg font-semibold">{{ __('partner.order_import') }}</h2>
                    <button type="button" wire:click="close" wire:loading.attr="disabled"
                        class="rounded border px-3 py-2 text-sm">{{ __('partner.close') }}</button>
                </div>
                <p class="mb-3 text-sm text-gray-600">{{ __('partner.order_import_description') }}</p>
                <p class="mb-4 font-semibold">{{ __('partner.season') }}: {{ $this->selectedSeason->code }} — {{ $this->selectedSeason->name }}</p>
                <div class="flex flex-wrap items-start gap-3">
                    @include('livewire.partner.partials.order-import-upload')
                </div>
                @include('livewire.partner.partials.order-import-feedback')
            </section>
        </div>
    @endif
</div>
