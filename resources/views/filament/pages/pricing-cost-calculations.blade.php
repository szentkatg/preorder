<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-600 shadow-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            Ez a munkafelület a bekerülési érték kalkuláció áttekintésére szolgál.
            A jobb felső oszlopkezelővel ki/be kapcsolhatók és átrendezhetők az oszlopok,
            a soroknál pedig a <span class="font-semibold">Részletek</span> gomb mutatja a teljes számítási hátteret.
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
