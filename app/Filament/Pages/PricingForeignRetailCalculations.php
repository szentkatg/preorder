<?php

namespace App\Filament\Pages;

use App\Filament\Support\Actions\CalculateForeignRetailAction;
use App\Models\AdminUiPreference;
use App\Services\Pricing\ForeignRetailCalculator;
use Livewire\Attributes\Locked;

class PricingForeignRetailCalculations extends PricingHufRetailCalculations
{
    // Shield caches permissions statically; this page must not share the HUF page's cache.
    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Devizás kisker árak';

    protected static ?string $title = 'Devizás bruttó kisker árak';

    protected static ?int $navigationSort = 37;

    #[Locked]
    public string $retailCurrency = 'EUR';

    public function mount(): void
    {
        parent::mount();
        $saved = AdminUiPreference::query()->where('user_id', auth()->id())
            ->where('preference_key', 'pricing_foreign_retail_currency')->first()?->value;
        if (in_array($saved['code'] ?? null, ForeignRetailCalculator::CURRENCIES, true)) {
            $this->retailCurrency = $saved['code'];
        }
    }

    public function selectRetailCurrency(string $code): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($code, ForeignRetailCalculator::CURRENCIES, true), 422);
        $this->clearPricingContextInteractions();
        $this->retailCurrency = $code;
        AdminUiPreference::updateOrCreate(['user_id' => auth()->id(), 'preference_key' => 'pricing_foreign_retail_currency'], ['value' => ['code' => $code]]);
        $this->refreshPricingContextTable();
    }

    public function retailCurrencyCode(): string
    {
        return $this->retailCurrency;
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Devizás kisker árak: végleges HUF kisker ár ÷ szezonárfolyam × országszorzó, majd kerekítés. Az effektív országszorzó és átlaga már a kerekítés, illetve a manuális ár hatását is mutatja.';
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_foreign_retail_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateForeignRetailAction::make(), $this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        return ['purchase_price' => 'Eredeti beszerzési ár', 'source_retail_price' => 'Forrás HUF kisker',
            'retail_exchange_rate' => 'Kisker árfolyam', 'base_price' => 'Átváltott HUF kisker',
            'multiplier' => 'Effektív országszorzó és átlaga', 'calculation_multiplier' => 'Kalkulációs országszorzó',
            'unrounded_price' => 'Kerekítés előtti ár', 'calculated_price' => 'Kalkulált kisker',
            'final_price' => 'Végleges kisker', 'previous_season_price' => 'Előző szezon kisker ár'];
    }
}
