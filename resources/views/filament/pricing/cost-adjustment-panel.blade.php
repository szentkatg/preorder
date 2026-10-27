<div wire:partial="pricing-cost-adjustment" @if (! ($this->pendingParameterAdjustment ?? null)) hidden @endif>
@php($pendingAdjustment = $this->pendingParameterAdjustment ?? null)
@php($selectedAffectedRows = $pendingAdjustment['scope_counts'][$this->pendingParameterScope ?? ''] ?? null)

@if ($pendingAdjustment)
    <div class="pricing-inline-parameter-panel">
        <div class="pricing-inline-parameter-text">
            <div class="pricing-inline-parameter-title">
                Paraméter módosítás megerősítése
            </div>

            <div class="pricing-inline-parameter-summary">
                <span class="pricing-inline-parameter-pill">
                    {{ $pendingAdjustment['parameter_label'] ?? 'Paraméter' }}:
                    {{ number_format((float) ($pendingAdjustment['value'] ?? 0), 2, ',', ' ') }}%
                </span>

                @if (filled($pendingAdjustment['model_code'] ?? null))
                    <span>Modell: {{ $pendingAdjustment['model_code'] }}</span>
                @endif

                @if (filled($pendingAdjustment['supplier_name'] ?? null))
                    <span>Beszállító: {{ $pendingAdjustment['supplier_name'] }}</span>
                @endif

                @if ($selectedAffectedRows !== null)
                    <span class="pricing-inline-parameter-impact">
                        A kiválasztott szűkítés várhatóan {{ number_format((int) $selectedAffectedRows, 0, ',', ' ') }} sort érint.
                    </span>
                @endif
            </div>
        </div>

        <div class="pricing-inline-parameter-actions">
            <label class="pricing-inline-parameter-field">
                <span>Érvényesítés szintje</span>
                <select wire:model.live="pendingParameterScope">
                    @foreach (($pendingAdjustment['scope_options'] ?? []) as $scope => $label)
                        <option value="{{ $scope }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <button
                type="button"
                class="pricing-inline-parameter-save"
                wire:click="confirmPendingParameterAdjustment"
                wire:loading.attr="disabled"
            >
                Mentés és újraszámítás
            </button>

            <button
                type="button"
                class="pricing-inline-parameter-cancel"
                wire:click="cancelPendingParameterAdjustment"
                wire:loading.attr="disabled"
            >
                Mégse
            </button>
        </div>
    </div>
@endif
</div>
