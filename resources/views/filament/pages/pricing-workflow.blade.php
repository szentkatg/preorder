<x-filament-panels::page>
    <div class="pricing-workflow" wire:poll.60s>
        <section class="pricing-workflow-project" aria-label="Árprojekt kiválasztása">
            <label for="workflow-project">Árprojekt</label>
            <select id="workflow-project" wire:change="selectPricingProject($event.target.value)" wire:loading.attr="disabled">
                <option value="" @selected($this->pricingProjectId === null)>Válassz árprojektet…</option>
                @foreach ($this->pricingProjectOptions as $project)
                    <option value="{{ $project->id }}" @selected($this->pricingProjectId === (int) $project->id)>
                        {{ $project->name }} — {{ $project->season?->name }} ({{ $project->status === 'closed' ? 'Lezárt' : ($project->status === 'draft' ? 'Tervezet' : 'Aktív') }})
                    </option>
                @endforeach
            </select>
            <label class="pricing-workflow-archive"><input type="checkbox" @checked($this->pricingArchive)
                wire:change="setPricingArchive($event.target.checked)" wire:loading.attr="disabled"> Archív megtekintés</label>
            <x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">Frissítés</x-filament::button>
        </section>
        @if (! $this->pricingSelectedProject)
            <p role="status">Válassz árprojektet. Kiválasztás nélkül nem összesítünk más projekteket vagy szezonokat.</p>
        @else
            <p class="pricing-workflow-help">
                Csak a kiválasztott árprojekt mentett kalkulációit számoljuk, nem a publikált árlistákat.
                Az összes darabszám tartalmazza a zárójelben jelzett outlet ársorokat is.
                Függő a nem jóváhagyott, felülvizsgálandó, nem pozitív vagy elégtelen outlet kedvezményű ár.
                A bekerülési értékeknél nincs külön jóváhagyás: a „Rendben” az érvényes, felülvizsgálatmentes sorokat jelenti.
                A még ki nem számított árak nem részei a darabszámoknak. Az adatok percenként automatikusan frissülnek.
                @if ($this->pricingArchive) Lezárt árprojekt: csak megtekintés. @endif
            </p>
        @endif
        <ol class="pricing-workflow-steps">
            @foreach ($this->workflowSteps as $key => $step)
                <li class="pricing-workflow-step" data-workflow-step="{{ $key }}" wire:key="workflow-{{ $key }}">
                    <div class="pricing-workflow-step-heading">
                        <span class="pricing-workflow-number">{{ $loop->iteration }}</span>
                        <h2>{{ $step['title'] }}</h2>
                    </div>
                    <p>{{ $step['description'] }}</p>
                    @if ($step['counts'] !== null)
                        <dl class="pricing-workflow-counts">
                            @foreach (['total' => 'Összes tétel', 'approved' => (in_array($key, ['cost', 'overview']) ? 'Rendben' : 'Jóváhagyott'), 'pending' => 'Függő'] as $count => $label)
                                <div class="pricing-workflow-count-{{ $count }}">
                                    <dt>
                                        @if (isset($step['countUrls'][$count]))
                                            <a href="{{ $step['countUrls'][$count] }}"
                                                wire:click.prevent="openPricingStep('{{ $key }}', '{{ $count }}')"
                                                aria-label="{{ $step['title'] }} — {{ $label }}: {{ $step['counts'][$count] }}">
                                                {{ $label }}
                                            </a>
                                        @else
                                            {{ $label }}
                                        @endif
                                    </dt>
                                    <dd data-workflow-count="{{ $count }}">{{ number_format($step['counts'][$count], 0, ',', ' ') }}
                                        @if ($step['counts']['outlet_total'])
                                            <small>({{ number_format($step['counts']['outlet_'.$count], 0, ',', ' ') }} outlet)</small>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    @if ($step['url'])
                        <x-filament::button tag="a" :href="$step['url']" wire:click.prevent="openPricingStep('{{ $key }}')" wire:loading.attr="disabled" icon="heroicon-o-arrow-right">Megnyitás</x-filament::button>
                    @else
                        <span class="pricing-workflow-help">{{ $this->pricingSelectedProject ? 'Az oldalhoz nincs hozzáférésed.' : 'Előbb válassz árprojektet.' }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($url = $this->projectDetailsUrl())
            <section class="pricing-workflow-project">
                <div><h2>Lezárás, publikálás és ERP árexport</h2>
                    <p>Az árprojekt adatlapján érhetők el, a megfelelő jogosultságokkal. Lezáráskor a rendszer ismét ellenőrzi az árak aktualitását és jóváhagyását.</p></div>
                <x-filament::button tag="a" :href="$url" color="gray">Árprojekt adatlapja</x-filament::button>
            </section>
        @endif
    </div>
</x-filament-panels::page>
