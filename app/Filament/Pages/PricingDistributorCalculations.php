<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\DistributorCalculationsTable;
use App\Filament\Support\Actions\CalculateDistributorAction;
use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingVatComparison;
use App\Models\AdminUiPreference;
use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\DistributorComparison;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class PricingDistributorCalculations extends PricingHufRetailCalculations
{
    use HasPricingVatComparison;

    protected static ?string $pagePermissionKey = null;

    protected static ?string $navigationLabel = 'Disztribútori árak';

    protected static ?string $title = 'EUR nettó disztribútori árak';

    protected static ?int $navigationSort = 39;

    #[Locked]
    public ?int $distributorPriceListId = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        // Preserve existing display precision/widths once; the two workspaces then evolve independently.
        foreach (['column_widths', 'decimal_places'] as $suffix) {
            $previous = AdminUiPreference::where('user_id', auth()->id())
                ->where('preference_key', 'pricing_wholesale_calculation_'.$suffix)->first();
            if ($previous) {
                AdminUiPreference::firstOrCreate(['user_id' => auth()->id(),
                    'preference_key' => $this->pricingPreferencePrefix().'_'.$suffix], ['value' => $previous->value]);
            }
        }
        parent::mount();
        $this->loadPricingVatComparison();
        $saved = AdminUiPreference::where('user_id', auth()->id())->where('preference_key', 'pricing_distributor_display')->first();
        $previous = $saved ? $saved->value : AdminUiPreference::where('user_id', auth()->id())
            ->where('preference_key', 'pricing_wholesale_display')->first()?->value;
        $id = $previous['distributor_price_list_id'] ?? null;
        $this->distributorPriceListId = is_scalar($id) && ctype_digit((string) $id) ? (int) $id : null;
        $this->refreshDistributorSelection();
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
        return $this->distributorPriceLists->get($this->distributorPriceListId);
    }

    private function refreshDistributorSelection(): void
    {
        unset($this->distributorPriceLists, $this->selectedDistributorPriceList, $this->averageDistributorNetNetMultiplier);
        if (! $this->distributorPriceLists->has($this->distributorPriceListId)) {
            $this->distributorPriceListId = $this->distributorPriceLists->count() === 1 ? (int) $this->distributorPriceLists->first()->id : null;
        }
    }

    protected function refreshPricingContextTable(): void
    {
        $this->refreshDistributorSelection();
        parent::refreshPricingContextTable();
        AdminUiPreference::updateOrCreate(['user_id' => auth()->id(), 'preference_key' => 'pricing_distributor_display'],
            ['value' => ['distributor_price_list_id' => $this->distributorPriceListId]]);
    }

    public function selectDistributorPriceList(mixed $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($id === null || $id === '' || (is_scalar($id) && ctype_digit((string) $id)), 422);
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

    public function retailCurrencyCode(): string
    {
        return 'EUR';
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'Nettó disztribútori ár = végleges nettó nagyker ár × (1 − kedvezmény% / 100), majd saját kerekítés. A nagyker alap csak olvasható; itt kizárólag a disztribútori árakat szerkeszted és hagyod jóvá. Az ÁFA csak a nettó/nettó összehasonlítást befolyásolja. Az átlag a szűrt sorok számtani átlaga.';
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_distributor_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateDistributorAction::make(), $this->pricingDecimalPlacesAction()];
    }

    protected function pricingDecimalColumns(): array
    {
        return ['base_price' => 'Összehasonlító bruttó kisker EUR', 'final_price' => 'Forrás nettó nagyker EUR',
            'distributor_discount_percent' => 'Disztribútori kedvezmény %',
            'distributor_calculated_price' => 'Kalkulált disztribútori EUR', 'distributor_final_price' => 'Végleges disztribútori EUR',
            'distributor_net_net_multiplier' => 'Disztribútori nettó/nettó szorzó'];
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure(DistributorCalculationsTable::configure($table)
            ->query($this->scopePricingProjectContext(PricingCalculationRow::query()->where('price_type', 'wholesale')
                ->where('price_list_id', $this->selectedDistributorPriceList?->source_wholesale_price_list_id ?? 0)
                ->whereHas('currency', fn ($query) => $query->where('code', 'EUR'))
                ->with(['pricingProject.season', 'product.itemMainGroup', 'color', 'priceList', 'currency', 'supplier',
                    'distributorRows' => fn ($query) => $query->where('price_list_id', $this->selectedDistributorPriceList?->id ?? 0)->with('priceList')]))),
            PricingCalculationRow::class, withViewAction: false);
    }
}
