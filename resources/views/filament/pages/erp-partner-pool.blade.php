<x-filament-panels::page>
    <p role="status">
        @if ($this->syncState?->synced_at)
            Utolsó sikeres ERP-pillanatkép: {{ $this->syncState->synced_at->timezone('Europe/Budapest')->format('Y.m.d. H:i') }}.
            {{ number_format($this->syncState->row_count, 0, ',', ' ') }} címsor,
            {{ number_format($this->syncState->partner_count, 0, ',', ' ') }} partner.
            @if ($this->syncState->synced_at->lt(now()->subHours(36)))
                <strong>Figyelem: a pillanatkép 36 óránál régebbi. Ellenőrizd az éjszakai szinkront.</strong>
            @endif
        @else
            Még nincs sikeresen betöltött ERP-pillanatkép. Az első ERP-frissítésig a lista üres.
        @endif
        Az átvétel csak a kiválasztott rekordot érinti; a pool frissítése nem módosítja a már átvett törzsadatokat.
    </p>
    @include('filament.pricing.column-width-actions')
    <div
        data-pricing-resizable-columns
        data-pricing-fit-query="{{ $this->pricingColumnFitQueryKey() }}"
        data-pricing-storage-key="{{ $this->pricingColumnStorageKey() }}"
        data-pricing-server-widths="{{ json_encode($this->pricingColumnWidths) }}"
    >
        {{ $this->table }}
    </div>
    @include('filament.pricing.resizable-columns-styles')
    @include('filament.pricing.resizable-columns-scripts')
</x-filament-panels::page>
