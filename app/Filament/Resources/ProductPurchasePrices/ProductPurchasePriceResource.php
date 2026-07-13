<?php

namespace App\Filament\Resources\ProductPurchasePrices;

use App\Filament\Resources\ProductPurchasePrices\Pages\CreateProductPurchasePrice;
use App\Filament\Resources\ProductPurchasePrices\Pages\EditProductPurchasePrice;
use App\Filament\Resources\ProductPurchasePrices\Pages\ListProductPurchasePrices;
use App\Filament\Resources\ProductPurchasePrices\Schemas\ProductPurchasePriceForm;
use App\Filament\Resources\ProductPurchasePrices\Tables\ProductPurchasePricesTable;
use App\Models\ProductPurchasePrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductPurchasePriceResource extends Resource
{
    protected static ?string $model = ProductPurchasePrice::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Törzsadatok';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Beszerzési árak';

    protected static ?string $modelLabel = 'beszerzési ár';

    protected static ?string $pluralModelLabel = 'beszerzési árak';

    protected static ?string $recordTitleAttribute =
        'product_purchase_price_id';

    public static function form(Schema $schema): Schema
    {
        return ProductPurchasePriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductPurchasePricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductPurchasePrices::route('/'),
            'create' => CreateProductPurchasePrice::route('/create'),
            'edit' => EditProductPurchasePrice::route('/{record}/edit'),
        ];
    }
}