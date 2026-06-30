<?php

namespace App\Filament\Resources\ItemAssortments;

use App\Filament\Resources\ItemAssortments\Pages\CreateItemAssortment;
use App\Filament\Resources\ItemAssortments\Pages\EditItemAssortment;
use App\Filament\Resources\ItemAssortments\Pages\ListItemAssortments;
use App\Filament\Resources\ItemAssortments\Schemas\ItemAssortmentForm;
use App\Filament\Resources\ItemAssortments\Tables\ItemAssortmentsTable;
use App\Models\ItemAssortment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ItemAssortmentResource extends Resource
{
    protected static ?string $model = ItemAssortment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ItemAssortmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemAssortmentsTable::configure($table);
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
            'index' => ListItemAssortments::route('/'),
            'create' => CreateItemAssortment::route('/create'),
            'edit' => EditItemAssortment::route('/{record}/edit'),
        ];
    }
}
