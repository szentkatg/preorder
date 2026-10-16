<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\WholesaleCalculationsTable;
use App\Filament\Support\Actions\CalculateWholesaleAction;
use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingVatComparison;
use App\Models\AdminUiPreference;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\PricingMultiplierAverage;
use App\Services\Pricing\WholesaleCalculator;
use Filament\Tables\Table;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class PricingWholesaleCalculations extends PricingHufRetailCalculations
{
    use HasPricingVatComparison;

    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Nagyker árak';

    protected static ?string $title = 'Nettó nagyker árak';

    protected static ?int $navigationSort = 38;

    #[Locked]
    public string $wholesaleCurrency = 'HUF';

    public function mount(): void
    {
        parent::mount();
        $preference = AdminUiPreference::where('user_id', auth()->id())->where('preference_key', 'pricing_wholesale_display')->first()?->value;
        if (in_array($preference['currency'] ?? null, WholesaleCalculator::CURRENCIES, true)) {
            $this->wholesaleCurrency = $preference['currency'];
        }
        $this->loadPricingVatComparison();
    }

    public function selectWholesaleCurrency(string $code): void
    {
        abort_unless(static::canAccess() && in_array($code, WholesaleCalculator::CURRENCIES, true), 403);
        $this->clearPricingContextInteractions();
        $this->wholesaleCurrency = $code;
        $this->refreshPricingContextTable();
    }

    private function saveWholesaleDisplay(): void
    {
        $preference = AdminUiPreference::firstOrNew(['user_id' => auth()->id(), 'preference_key' => 'pricing_wholesale_display']);
        $preference->value = array_replace($preference->value ?? [], ['currency' => $this->wholesaleCurrency]);
        $preference->save();
    }

    protected function refreshPricingContextTable(): void
    {
        parent::refreshPricingContextTable();
        $this->saveWholesaleDisplay();
    }

    public function retailCurrencyCode(): string
    {
        return $this->wholesaleCurrency;
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Nettó nagyker ár = végleges bruttó kisker ár ÷ nagyker szorzó, majd kerekítés. A nettó/nettó szorzó a kisker árat az összehasonlítás kedvéért nettósítja: HUF 27%, EUR a megadott ÁFA alapján. Az ÁFA nem változtatja a nagyker árat vagy a jóváhagyást. A súlyozott átlagok a teljes szűrt találati halmaz osztandóinak összege / osztóinak összege.';
    }

    #[Computed]
    public function averageRetailMultiplier(): ?float
    {
        return PricingMultiplierAverage::calculate($this->getFilteredTableQuery(),
            'CASE WHEN base_price > 0 THEN base_price ELSE NULL END', 'COALESCE(manual_price, retained_price, calculated_price)');
    }

    #[Computed]
    public function averageNetNetMultiplier(): ?float
    {
        $vat = $this->wholesaleVatPercent();
        $average = $this->averageRetailMultiplier;

        return $vat === null || $average === null ? null : $average / (1 + $vat / 100);
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_wholesale_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateWholesaleAction::make(), CalculateWholesaleAction::make(all: true), $this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        return ['base_price' => 'Bruttó kisker ár', 'calculation_multiplier' => 'Kalkulációs nagyker szorzó',
            'wholesale_multiplier' => 'Tényleges nagyker szorzó', 'net_net_multiplier' => 'Nettó/nettó szorzó',
            'unrounded_price' => 'Kerekítés előtti nettó nagyker', 'calculated_price' => 'Kalkulált nettó nagyker', 'final_price' => 'Végleges nettó nagyker'];
    }

    public function table(Table $table): Table
    {
        $table = WholesaleCalculationsTable::configure($table, $this->retailCurrencyCode());
        $filters = $table->getFilters();
        unset($filters['pricing_project_id']);

        return AdminResourceTable::configure($table->filters(array_values($filters))
            ->query($this->scopePricingProjectContext(PricingCalculationRow::query()->where('price_type', 'wholesale')
                ->whereHas('currency', fn ($query) => $query->where('code', $this->retailCurrencyCode()))
                ->with(['pricingProject.season', 'product.itemMainGroup', 'color', 'priceList', 'currency', 'pricingRoundingRule', 'supplier', 'sourceRetailRow.priceList']))),
            PricingCalculationRow::class, withViewAction: false);
    }
}
