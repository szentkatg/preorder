<?php

namespace App\Filament\Resources\PricingCalculationRows;

use App\Filament\Resources\PricingCalculationRows\Pages\CreatePricingCalculationRow;
use App\Filament\Resources\PricingCalculationRows\Pages\EditPricingCalculationRow;
use App\Filament\Resources\PricingCalculationRows\Pages\ListPricingCalculationRows;
use App\Filament\Resources\PricingCalculationRows\Pages\ViewPricingCalculationRow;
use App\Filament\Resources\PricingCalculationRows\Schemas\PricingCalculationRowForm;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingCalculationRowResource extends Resource
{
    protected static ?string $model = PricingCalculationRow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $modelLabel = 'árkalkulációs sor';

    protected static ?string $pluralModelLabel = 'árkalkulációs sorok';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return PricingCalculationRowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingCalculationRowsTable::configure($table),
            PricingCalculationRow::class,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingCalculationRows::route('/'),
            'create' => CreatePricingCalculationRow::route('/create'),
            'view' => ViewPricingCalculationRow::route('/{record}'),
            'edit' => EditPricingCalculationRow::route('/{record}/edit'),
        ];
    }
}
