<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\HufRetailCalculationsTable;
use App\Filament\Support\Actions\CalculateHufRetailAction;
use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasOutletPricing;
use App\Filament\Support\Concerns\HasPricingGridInteractions;
use App\Filament\Support\Concerns\HasPricingProjectContext;
use App\Filament\Support\Concerns\HasPricingTablePreferences;
use App\Filament\Support\Concerns\HasProductPricingEditor;
use App\Models\PricingCalculationRow;
use App\Services\Pricing\PreviousSeasonRetailPriceService;
use App\Services\Pricing\PricingMultiplierAverage;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

class PricingHufRetailCalculations extends Page implements HasTable
{
    use HasOutletPricing;
    use HasPageShield {
        canAccess as protected canAccessUsingPagePermission;
    }
    use HasPricingGridInteractions;
    use HasPricingProjectContext;
    use HasPricingTablePreferences, InteractsWithTable {
        HasPricingTablePreferences::loadTableColumnsFromSession insteadof InteractsWithTable;
        HasPricingTablePreferences::persistTableColumns insteadof InteractsWithTable;
        HasPricingTablePreferences::persistHasReorderedTableColumns insteadof InteractsWithTable;
        updateTableColumnState as protected updatePricingTableColumnState;
    }
    use HasProductPricingEditor;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'HUF kisker árak';

    protected static ?string $title = 'HUF bruttó kisker árak';

    protected static ?int $navigationSort = 36;

    protected string $view = 'filament.pages.pricing-cost-calculations';

    public static function canAccess(): bool
    {
        return static::canAccessUsingPagePermission()
            && Gate::allows('viewAny', PricingCalculationRow::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && parent::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->initialisePricingProjectContext();
        unset($this->outletPriceLists);
        $this->pricingColumnWidths = $this->loadPricingColumnWidths();
        $this->pricingDecimalPlaces = $this->loadPricingDecimalPlaces();
        if ($this->outletPriceLists->isNotEmpty()) {
            AdminResourceTable::refreshPreservingState($this);
        }
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'HUF bruttó kisker árak: a Kisker szorzó minden sornál a végleges, kerekítés utáni ár / bekerülési érték. A súlyozott átlag a teljes szűrt találati halmaz végleges árainak összege / bekerülési értékeinek összege. A piros cél-beszerzési ár jelzi, ha a kalkulációs szorzó eléréséhez alkudni kell.';
    }

    #[Computed]
    public function averageRetailMultiplier(): ?float
    {
        return PricingMultiplierAverage::calculate($this->getFilteredTableQuery(),
            'COALESCE(manual_price, retained_price, calculated_price)', 'base_price');
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_huf_retail_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateHufRetailAction::make(fn () => $this->pricingSelectedProject), $this->calculateOutletAction(), $this->pricingDecimalPlacesAction()];
    }

    public function updateTableColumnState(string $column, string $record, mixed $input): mixed
    {
        abort_unless(static::canAccess() && $this->pricingWorkspaceIsEditable(), 403);

        if ($column === ($this instanceof PricingDistributorCalculations ? 'distributor_manual_price' : 'manual_price')) {
            $this->setPricingManualPrice($record, $input);

            return null;
        }

        return $this->updatePricingTableColumnState($column, $record, $input);
    }

    protected function pricingDecimalColumns(): array
    {
        return [
            'purchase_price' => 'Eredeti beszerzési ár', 'base_price' => 'Bekerülési HUF',
            'multiplier' => 'Kisker szorzó', 'calculation_multiplier' => 'Kalkulációs szorzó',
            'unrounded_price' => 'Kerekítés előtti HUF', 'calculated_price' => 'Kalkulált kisker HUF',
            'final_price' => 'Végleges kisker HUF', 'target_purchase_price' => 'Cél beszerzési ár',
            'previous_season_price' => 'Előző szezon kisker ár',
            ...$this->outletDecimalColumns(),
        ];
    }

    public function table(Table $table): Table
    {
        $table = HufRetailCalculationsTable::configure($table, $this->retailCurrencyCode());
        $filters = $table->getFilters();
        unset($filters['pricing_project_id']);
        $table->filters(array_values($filters))
            ->modifyQueryUsing(fn (Builder $query) => app(PreviousSeasonRetailPriceService::class)->addColumns(
                $this->scopePricingProjectContext($query), $this->pricingSelectedProject?->season_id,
            ));

        return AdminResourceTable::configure(
            $table->query(
                PricingCalculationRow::query()->where('price_type', PricingCalculationRow::TYPE_RETAIL)
                    ->whereHas('currency', fn ($query) => $query->where('code', $this->retailCurrencyCode()))
                    ->with(['pricingProject.season', 'product.itemMainGroup', 'color', 'priceList', 'currency', 'pricingRoundingRule', 'supplier', 'purchaseCurrency', 'retailCostSource',
                        'outletRows' => fn ($query) => $query->whereIn('price_list_id', $this->outletPriceLists->pluck('id'))])
            ),
            PricingCalculationRow::class,
            withViewAction: false,
        );
    }

    public function retailCurrencyCode(): string
    {
        return 'HUF';
    }
}
