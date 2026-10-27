<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-4">
        <label>
            <span>Árprojekt</span>
            <select wire:change="selectPricingProject($event.target.value)" wire:loading.attr="disabled"
                style="border:1px solid currentColor;border-radius:.5rem;padding:.4rem;background:transparent">
                <option value="" @selected($this->pricingProjectId === null)>Válassz árprojektet…</option>
                @foreach ($this->pricingProjectOptions as $project)
                    <option value="{{ $project->id }}" @selected($this->pricingProjectId === (int) $project->id)>
                        {{ $project->name }} — {{ $project->season?->name }}
                    </option>
                @endforeach
            </select>
        </label>
        <label>
            <input type="checkbox" @checked($this->pricingArchive)
                wire:change="setPricingArchive($event.target.checked)" wire:loading.attr="disabled">
            Archív megtekintés
        </label>
    </div>
    <p>
        Árprojekt, árlista, főcsoport és végleges HUF kisker ár szerinti összesítés,
        a kézzel módosított árak figyelembevételével. Egy modell egy árkategórián belül egyszer szerepel;
        a színállások száma az érintett modellek aktív színeinek összege.
        Modell-szintű ár mellett is a terméktörzs aktív színeit számoljuk.
        Az Excel-export az aktuális szűrés összes találatát tartalmazza, nem csak az aktuális lapot.
    </p>
    {{ $this->table }}
</x-filament-panels::page>
