<?php

namespace App\Filament\Resources\PricingParameters;

use App\Filament\Resources\PricingParameters\Pages\CreatePricingParameter;
use App\Filament\Resources\PricingParameters\Pages\EditPricingParameter;
use App\Filament\Resources\PricingParameters\Pages\ListPricingParameters;
use App\Filament\Resources\PricingParameters\Pages\ViewPricingParameter;
use App\Filament\Resources\PricingParameters\Schemas\PricingParameterForm;
use App\Filament\Resources\PricingParameters\Tables\PricingParametersTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PricingParameter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PricingParameterResource extends Resource
{
    protected static ?string $model = PricingParameter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Árképzés';

    protected static ?string $modelLabel = 'árképzési paraméter';

    protected static ?string $pluralModelLabel = 'árképzési paraméterek';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return PricingParameterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PricingParametersTable::configure($table),
            PricingParameter::class,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPricingParameters::route('/'),
            'create' => CreatePricingParameter::route('/create'),
            'view' => ViewPricingParameter::route('/{record}'),
            'edit' => EditPricingParameter::route('/{record}/edit'),
        ];
    }
}
