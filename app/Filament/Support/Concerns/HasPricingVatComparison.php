<?php

namespace App\Filament\Support\Concerns;

use App\Models\AdminUiPreference;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

trait HasPricingVatComparison
{
    #[Locked]
    public ?float $eurVatPercent = null;

    protected function loadPricingVatComparison(): void
    {
        $saved = AdminUiPreference::where('user_id', auth()->id())
            ->where('preference_key', 'pricing_wholesale_display')->first()?->value;
        $vat = $saved['eur_vat_percent'] ?? null;
        if (is_numeric($vat) && is_finite((float) $vat) && $vat >= 0 && $vat <= 100) {
            $this->eurVatPercent = (float) $vat;
        }
    }

    public function setEurVatPercent(mixed $value): void
    {
        abort_unless(auth()->id() && static::canAccess(), 403);
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        $validated = Validator::make(['eurVatPercent' => $value === '' ? null : $value],
            ['eurVatPercent' => ['nullable', 'numeric', 'min:0', 'max:100']])->validate();
        $this->eurVatPercent = $validated['eurVatPercent'] === null ? null : (float) $validated['eurVatPercent'];
        $preference = AdminUiPreference::firstOrNew(['user_id' => auth()->id(), 'preference_key' => 'pricing_wholesale_display']);
        $preference->value = array_replace($preference->value ?? [], ['eur_vat_percent' => $this->eurVatPercent]);
        $preference->save();
        unset($this->averageNetNetMultiplier, $this->averageDistributorNetNetMultiplier);
        $this->resetTable();
    }

    public function wholesaleVatPercent(): ?float
    {
        return $this->retailCurrencyCode() === 'HUF' ? 27.0 : $this->eurVatPercent;
    }
}
