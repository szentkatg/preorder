<?php

namespace App\Filament\Resources\PricingProjects\RelationManagers;

use App\Filament\Resources\PricingCalculationRows\Schemas\PricingCalculationRowForm;
use App\Filament\Resources\PricingCalculationRows\Tables\PricingCalculationRowsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingCalculationRow;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class CalculationRowsRelationManager extends RelationManager
{
    protected static string $relationship = 'calculationRows';

    protected static ?string $title = 'Árkalkulációs sorok';

    public function form(Schema $schema): Schema
    {
        return PricingCalculationRowForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingCalculationRowsTable::configure($table),
            PricingCalculationRow::class,
            withViewAction: false,
        );
    }
}
