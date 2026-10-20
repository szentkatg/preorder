<x-filament-panels::page>
    <p>{{ $this->getRecord()->name }} — {{ $this->getRecord()->season?->name }}.
        Csak a szezon még egyik árprojektbe sem felvett termékei látszanak.
        A kijelölés után válaszd a „Kijelölt termékek felvétele” műveletet.</p>
    {{ $this->table }}
</x-filament-panels::page>
