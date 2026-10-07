<?php

namespace App\Filament\Resources\PricingProjects;

use App\Filament\Resources\PricingProjects\Pages\CreatePricingProject;
use App\Filament\Resources\PricingProjects\Pages\EditPricingProject;
use App\Filament\Resources\PricingProjects\Pages\ListPricingProjects;
use App\Filament\Resources\PricingProjects\Pages\ViewPricingProject;
use App\Filament\Resources\PricingProjects\RelationManagers\CalculationRowsRelationManager;
use App\Filament\Resources\PricingProjects\Schemas\PricingProjectForm;
use App\Filament\Resources\PricingProjects\Tables\PricingProjectsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingProject;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingProjectResource extends Resource
{
    protected static ?string $model = PricingProject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $modelLabel = 'árprojekt';

    protected static ?string $pluralModelLabel = 'árprojektek';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return PricingProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingProjectsTable::configure($table),
            PricingProject::class,
        );
    }

    public static function getRelations(): array
    {
        return [
            CalculationRowsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingProjects::route('/'),
            'create' => CreatePricingProject::route('/create'),
            'view' => ViewPricingProject::route('/{record}'),
            'edit' => EditPricingProject::route('/{record}/edit'),
        ];
    }
}
