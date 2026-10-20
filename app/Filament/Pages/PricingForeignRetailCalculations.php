<?php

namespace App\Filament\Pages;

use App\Filament\Support\Actions\CalculateForeignRetailAction;
use App\Filament\Support\AdminResourceTable;
use App\Models\AdminUiPreference;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\ForeignRetailCalculator;
use App\Services\Pricing\PricingMultiplierAverage;
use Livewire\Attributes\Computed;
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
        $initialCurrency = $this->retailCurrency;
        $saved = AdminUiPreference::query()->where('user_id', auth()->id())
            ->where('preference_key', 'pricing_foreign_retail_currency')->first()?->value;
        if (in_array($saved['code'] ?? null, ForeignRetailCalculator::CURRENCIES, true)) {
            $this->retailCurrency = $saved['code'];
        }
        unset($this->outletPriceLists);
        if ($this->retailCurrency !== $initialCurrency) {
            $this->pricingDecimalPlaces = $this->loadPricingDecimalPlaces();
            AdminResourceTable::refreshPreservingState($this);
        }
    }

    public function selectRetailCurrency(string $code): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($code, ForeignRetailCalculator::CURRENCIES, true), 422);
        $this->clearPricingContextInteractions();
        $this->retailCurrency = $code;
        unset($this->outletPriceLists);
        AdminUiPreference::updateOrCreate(['user_id' => auth()->id(), 'preference_key' => 'pricing_foreign_retail_currency'], ['value' => ['code' => $code]]);
        $this->refreshPricingContextTable();
    }

    public function retailCurrencyCode(): string
    {
        return $this->retailCurrency;
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Devizás kisker árak: végleges HUF kisker ár ÷ szezonárfolyam × országszorzó, majd kerekítés. A bekerülési szorzó = végleges devizás ár × mentett árfolyam ÷ a HUF forrássor bekerülési alapja. Mindkét súlyozott átlag a teljes szűrt találati halmaz osztandóinak összege / osztóinak összege; a kerekítés és a manuális ár hatását is mutatja.';
    }

    #[Computed]
    public function averageCostBasedMultiplier(): ?float
    {
        return PricingMultiplierAverage::calculate($this->getFilteredTableQuery(),
            'CASE WHEN retail_exchange_rate > 0 THEN COALESCE(manual_price, retained_price, calculated_price) * retail_exchange_rate ELSE NULL END',
            PricingCalculationRow::costBasedMultiplierBaseSql());
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_foreign_retail_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateForeignRetailAction::make(), CalculateForeignRetailAction::make(all: true), $this->calculateOutletAction(), $this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        return ['purchase_price' => 'Eredeti beszerzési ár', 'source_retail_price' => 'Forrás HUF kisker',
            'retail_exchange_rate' => 'Kisker árfolyam', 'base_price' => 'Átváltott HUF kisker',
            'multiplier' => 'Effektív országszorzó', 'cost_based_multiplier' => 'Bekerülési szorzó',
            'calculation_multiplier' => 'Kalkulációs országszorzó',
            'unrounded_price' => 'Kerekítés előtti ár', 'calculated_price' => 'Kalkulált kisker',
            'final_price' => 'Végleges kisker', 'previous_season_price' => 'Előző szezon kisker ár', ...$this->outletDecimalColumns()];
    }
}
