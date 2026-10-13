<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\HufRetailCalculationsTable;
use App\Filament\Support\Actions\CalculateHufRetailAction;
use App\Filament\Support\AdminResourceTable;
use App\Filament\Support\Concerns\HasPricingTablePreferences;
use App\Models\PricingCalculationRow;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

class PricingHufRetailCalculations extends Page implements HasTable
{
    use HasPageShield {
        canAccess as protected canAccessUsingPagePermission;
    }
    use HasPricingTablePreferences, InteractsWithTable {
        HasPricingTablePreferences::loadTableColumnsFromSession insteadof InteractsWithTable;
        HasPricingTablePreferences::persistTableColumns insteadof InteractsWithTable;
        HasPricingTablePreferences::persistHasReorderedTableColumns insteadof InteractsWithTable;
    }

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
        $this->pricingColumnWidths = $this->loadPricingColumnWidths();
    }

    public function pricingWorkspaceDescription(): string
    {
        return 'HUF bruttó kisker árak: a Kisker szorzó minden sornál a végleges, kerekítés utáni ár / bekerülési érték. Az átlag a szűrt sorok szorzóinak számtani átlaga. A piros cél-beszerzési ár jelzi, ha a kalkulációs szorzó eléréséhez alkudni kell.';
    }

    #[Computed]
    public function averageRetailMultiplier(): ?float
    {
        $query = clone $this->getFilteredTableQuery();
        $average = $query->toBase()->avg(DB::raw(PricingCalculationRow::EFFECTIVE_MULTIPLIER_SQL));

        return $average === null ? null : (float) $average;
    }

    protected function pricingPreferencePrefix(): string
    {
        return 'pricing_huf_retail_calculation';
    }

    protected function getHeaderActions(): array
    {
        return [CalculateHufRetailAction::make()];
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            HufRetailCalculationsTable::configure($table)->query(
                PricingCalculationRow::query()->where('price_type', PricingCalculationRow::TYPE_RETAIL)
                    ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
                    ->with(['pricingProject.season', 'product.itemMainGroup', 'color', 'priceList', 'currency', 'pricingRoundingRule', 'supplier', 'purchaseCurrency'])
            ),
            PricingCalculationRow::class,
            withViewAction: false,
        );
    }
}
