<?php

namespace App\Filament\Resources\PriceListItems;

use App\Filament\Resources\PriceListItems\Pages\CreatePriceListItem;
use App\Filament\Resources\PriceListItems\Pages\EditPriceListItem;
use App\Filament\Resources\PriceListItems\Pages\ListPriceListItems;
use App\Filament\Resources\PriceListItems\Pages\ViewPriceListItem;
use App\Filament\Resources\PriceListItems\Schemas\PriceListItemForm;
use App\Filament\Resources\PriceListItems\Tables\PriceListItemsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PriceListItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PriceListItemResource extends Resource
{
    protected static ?string $model = PriceListItem::class;
    
    protected static bool $shouldRegisterNavigation = true;
    
    protected static string|\UnitEnum|null $navigationGroup = 'Törzsadatok';
    
    protected static ?string $navigationLabel = 'Árlista tételek';
    
    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PriceListItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            PriceListItemsTable::configure($table),
            PriceListItem::class,
        );
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
            'index' => ListPriceListItems::route('/'),
            'create' => CreatePriceListItem::route('/create'),
            'view' => ViewPriceListItem::route('/{record}'),
            'edit' => EditPriceListItem::route('/{record}/edit'),
        ];
    }
}
