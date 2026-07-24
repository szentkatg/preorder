<?php

namespace App\Filament\Resources\OrderSheetTypes;

use App\Filament\Resources\OrderSheetTypes\Pages\CreateOrderSheetType;
use App\Filament\Resources\OrderSheetTypes\Pages\EditOrderSheetType;
use App\Filament\Resources\OrderSheetTypes\Pages\ListOrderSheetTypes;
use App\Filament\Resources\OrderSheetTypes\Schemas\OrderSheetTypeForm;
use App\Filament\Resources\OrderSheetTypes\Tables\OrderSheetTypesTable;
use App\Models\OrderSheetType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderSheetTypeResource extends Resource
{
    protected static ?string $model = OrderSheetType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OrderSheetTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderSheetTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderSheetTypes::route('/'),
            'create' => CreateOrderSheetType::route('/create'),
            'edit' => EditOrderSheetType::route('/{record}/edit'),
        ];
    }
}
