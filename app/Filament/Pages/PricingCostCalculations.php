<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PricingCostCalculations extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon =
        'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $navigationLabel = 'Bekerülési értékek';

    protected static ?string $title = 'Bekerülési értékek';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.pricing-cost-calculations';

    public function table(Table $table): Table
    {
        $table = PricingCalculationRowsTable::configure($table)
            ->query(
                PricingCalculationRow::query()
                    ->where('price_type', PricingCalculationRow::TYPE_COST)
                    ->with([
                        'pricingProject',
                        'product.itemMainGroup',
                        'color',
                        'supplier',
                        'purchaseCurrency',
                        'currency',
                    ])
            )
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->where('price_type', PricingCalculationRow::TYPE_COST)
            );

        return AdminResourceTable::configure(
            $table,
            PricingCalculationRow::class,
            withViewAction: false,
        );
    }
}
