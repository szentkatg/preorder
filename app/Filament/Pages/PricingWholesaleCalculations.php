<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\WholesaleCalculationsTable;
use App\Filament\Support\Actions\CalculateDistributorAction;
use App\Filament\Support\Actions\CalculateWholesaleAction;
use App\Filament\Support\AdminResourceTable;
use App\Models\AdminUiPreference;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorComparison;
use App\Services\Pricing\WholesaleCalculator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class PricingWholesaleCalculations extends PricingHufRetailCalculations
{
    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Nagyker árak';

    protected static ?string $title = 'Nettó nagyker árak';

    protected static ?int $navigationSort = 38;

    #[Locked]
    public string $wholesaleCurrency = 'HUF';

    #[Locked]
    public ?float $eurVatPercent = null;

    #[Locked]
    public ?int $distributorPriceListId = null;

    public function mount(): void
    {
        parent::mount();
        $preference = AdminUiPreference::where('user_id', auth()->id())->where('preference_key', 'pricing_wholesale_display')->first()?->value;
        if (in_array($preference['currency'] ?? null, WholesaleCalculator::CURRENCIES, true)) {
            $this->wholesaleCurrency = $preference['currency'];
        }
        $vat = $preference['eur_vat_percent'] ?? null;
        if (is_numeric($vat) && is_finite((float) $vat) && $vat >= 0 && $vat <= 100) {
            $this->eurVatPercent = (float) $vat;
        }
        $id = $preference['distributor_price_list_id'] ?? null;
        $this->distributorPriceListId = is_scalar($id) && ctype_digit((string) $id) ? (int) $id : null;
        $this->refreshDistributorSelection();
    }

    public function selectWholesaleCurrency(string $code): void
    {
        abort_unless(static::canAccess() && in_array($code, WholesaleCalculator::CURRENCIES, true), 403);
        $this->clearPricingContextInteractions();
        $this->wholesaleCurrency = $code;
        $this->refreshPricingContextTable();
    }

    public function setEurVatPercent(mixed $value): void
    {
        abort_unless(auth()->id() && static::canAccess(), 403);
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        $value = $value === '' ? null : $value;
        $validated = Validator::make(['eurVatPercent' => $value], ['eurVatPercent' => ['nullable', 'numeric', 'min:0', 'max:100']])->validate();
        $this->eurVatPercent = $validated['eurVatPercent'] === null ? null : (float) $validated['eurVatPercent'];
        $this->saveWholesaleDisplay();
        unset($this->averageNetNetMultiplier, $this->averageDistributorNetNetMultiplier);
        $this->resetTable();
    }

    private function saveWholesaleDisplay(): void
    {
        AdminUiPreference::updateOrCreate(['user_id' => auth()->id(), 'preference_key' => 'pricing_wholesale_display'],
            ['value' => ['currency' => $this->wholesaleCurrency, 'eur_vat_percent' => $this->eurVatPercent,
                'distributor_price_list_id' => $this->distributorPriceListId]]);
    }

    #[Computed]
    public function distributorPriceLists(): Collection
    {
        return PriceList::query()->where('type', 'distributor')->when(! $this->pricingArchive, fn ($query) => $query->where('active', true))
            ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))
            ->whereHas('sourceWholesalePriceList', fn ($query) => $query->where('type', 'wholesale')
                ->whereHas('currency', fn ($query) => $query->where('code', 'EUR')))
            ->with('sourceWholesalePriceList')->orderBy('code')->get()->keyBy('id');
    }

    #[Computed]
    public function selectedDistributorPriceList(): ?PriceList
    {
        return $this->wholesaleCurrency === 'EUR' ? $this->distributorPriceLists->get($this->distributorPriceListId) : null;
    }

    private function refreshDistributorSelection(): void
    {
        unset($this->distributorPriceLists, $this->selectedDistributorPriceList, $this->averageDistributorNetNetMultiplier);
        if ($this->wholesaleCurrency === 'EUR' && ! $this->distributorPriceLists->has($this->distributorPriceListId)) {
            $this->distributorPriceListId = $this->distributorPriceLists->count() === 1 ? (int) $this->distributorPriceLists->first()->id : null;
        }
    }

    protected function refreshPricingContextTable(): void
    {
        parent::refreshPricingContextTable();
        $this->refreshDistributorSelection();
        $this->saveWholesaleDisplay();
    }

    public function selectDistributorPriceList(mixed $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->wholesaleCurrency === 'EUR'
            && ($id === null || $id === '' || (is_scalar($id) && ctype_digit((string) $id))), 422);
        $selected = filled($id) ? $this->distributorPriceLists->get((int) $id) : null;
        abort_if(filled($id) && ! $selected, 422);
        $this->clearPricingContextInteractions();
        $this->distributorPriceListId = $selected?->id;
        $this->refreshPricingContextTable();
    }

    public function distributorRow(PricingCalculationRow $source, bool $fresh = false): ?PricingCalculationRow
    {
        $target = $this->selectedDistributorPriceList;
        if (! $target || $source->pricing_project_id != $this->pricingProjectId || $source->price_type !== 'wholesale'
            || $source->price_list_id != $target->source_wholesale_price_list_id || $source->currency_id != $target->currency_id) {
            return null;
        }
        if (! $fresh && $source->relationLoaded('distributorRows')) {
            return $source->distributorRows->first(fn ($row) => $row->price_list_id == $target->id
                && $row->pricing_project_id == $source->pricing_project_id && $row->product_id == $source->product_id
                && $row->color_key == $source->color_key && $row->currency_id == $source->currency_id);
        }

        return $source->distributorRows()->where('pricing_project_id', $source->pricing_project_id)->where('product_id', $source->product_id)
            ->where('color_key', $source->color_key)->where('currency_id', $source->currency_id)->where('price_list_id', $target->id)->with('priceList')->first();
    }

    #[Computed]
    public function averageDistributorNetNetMultiplier(): ?float
    {
        $average = (clone $this->getFilteredTableQuery())->toBase()->avg(DB::raw(DistributorComparison::sql(
            'distributor_net_net_multiplier', $this->selectedDistributorPriceList?->id, $this->wholesaleVatPercent())));

        return $average === null ? null : (float) $average;
    }

    public function wholesaleVatPercent(): ?float
    {
        return $this->wholesaleCurrency === 'HUF' ? 27.0 : $this->eurVatPercent;
    }

    public function retailCurrencyCode(): string
    {
        return $this->wholesaleCurrency;
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Nettó nagyker ár = végleges bruttó kisker ár ÷ nagyker szorzó, majd kerekítés. A nettó/nettó szorzó a kisker árat az összehasonlítás kedvéért nettósítja: HUF 27%, EUR a megadott ÁFA alapján. Az ÁFA nem változtatja a nagyker árat vagy a jóváhagyást. Az átlagok a szűrt sorok számtani átlagai.';
    }

    #[Computed]
    public function averageRetailMultiplier(): ?float
    {
        $average = (clone $this->getFilteredTableQuery())->toBase()->avg(DB::raw(PricingCalculationRow::WHOLESALE_MULTIPLIER_SQL));

        return $average === null ? null : (float) $average;
    }

    #[Computed]
    public function averageNetNetMultiplier(): ?float
    {
        $average = (clone $this->getFilteredTableQuery())->toBase()->avg(DB::raw(PricingCalculationRow::netNetMultiplierSql($this->wholesaleVatPercent())));

        return $average === null ? null : (float) $average;
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_wholesale_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateWholesaleAction::make(), CalculateWholesaleAction::make(all: true), CalculateDistributorAction::make(), $this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        return ['base_price' => 'Bruttó kisker ár', 'calculation_multiplier' => 'Kalkulációs nagyker szorzó',
            'wholesale_multiplier' => 'Tényleges nagyker szorzó', 'net_net_multiplier' => 'Nettó/nettó szorzó',
            'unrounded_price' => 'Kerekítés előtti nettó nagyker', 'calculated_price' => 'Kalkulált nettó nagyker', 'final_price' => 'Végleges nettó nagyker',
            'distributor_discount_percent' => 'Disztribútori kedvezmény %',
            'distributor_calculated_price' => 'Kalkulált disztribútori EUR', 'distributor_final_price' => 'Végleges disztribútori EUR',
            'distributor_net_net_multiplier' => 'Disztribútori nettó/nettó szorzó'];
    }

    public function table(Table $table): Table
    {
        $table = WholesaleCalculationsTable::configure($table, $this->retailCurrencyCode());
        $filters = $table->getFilters();
        unset($filters['pricing_project_id']);

        return AdminResourceTable::configure($table->filters(array_values($filters))
            ->query($this->scopePricingProjectContext(PricingCalculationRow::query()->where('price_type', 'wholesale')
                ->whereHas('currency', fn ($query) => $query->where('code', $this->retailCurrencyCode()))
                ->when($this->selectedDistributorPriceList, fn ($query) => $query->where('price_list_id', $this->selectedDistributorPriceList->source_wholesale_price_list_id))
                ->with(['pricingProject.season', 'product.itemMainGroup', 'color', 'priceList', 'currency', 'pricingRoundingRule', 'supplier', 'sourceRetailRow.priceList',
                    'distributorRows' => fn ($query) => $query->where('price_list_id', $this->selectedDistributorPriceList?->id ?? 0)->with('priceList')]))),
            PricingCalculationRow::class, withViewAction: false);
    }
}
