<?php

namespace App\Filament\Resources\PriceLists;

use App\Filament\Resources\PriceLists\RelationManagers;
use App\Filament\Resources\PriceLists\Pages\CreatePriceList;
use App\Filament\Resources\PriceLists\Pages\EditPriceList;
use App\Filament\Resources\PriceLists\Pages\ListPriceLists;
use App\Filament\Resources\PriceLists\Pages\ViewPriceList;
use App\Filament\Resources\PriceLists\Schemas\PriceListForm;
use App\Filament\Resources\PriceLists\Tables\PriceListsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PriceList;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PriceListForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PriceListsTable::configure($table),
            PriceList::class,
        );
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceLists::route('/'),
            'create' => CreatePriceList::route('/create'),
            'view' => ViewPriceList::route('/{record}'),
            'edit' => EditPriceList::route('/{record}/edit'),
        ];
    }
}
