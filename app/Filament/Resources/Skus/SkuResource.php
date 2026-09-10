<?php

namespace App\Filament\Resources\Skus;

use App\Filament\Resources\Skus\Pages\CreateSku;
use App\Filament\Resources\Skus\Pages\EditSku;
use App\Filament\Resources\Skus\Pages\ListSkus;
use App\Filament\Resources\Skus\Pages\ViewSku;
use App\Filament\Resources\Skus\Schemas\SkuForm;
use App\Filament\Resources\Skus\Tables\SkusTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\Sku;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SkuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            SkusTable::configure($table),
            Sku::class,
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
            'index' => ListSkus::route('/'),
            'create' => CreateSku::route('/create'),
            'view' => ViewSku::route('/{record}'),
            'edit' => EditSku::route('/{record}/edit'),
        ];
    }
}
