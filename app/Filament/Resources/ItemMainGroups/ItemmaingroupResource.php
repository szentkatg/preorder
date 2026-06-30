<?php

namespace App\Filament\Resources\Itemmaingroups;

use App\Filament\Resources\Itemmaingroups\Pages\CreateItemmaingroup;
use App\Filament\Resources\Itemmaingroups\Pages\EditItemmaingroup;
use App\Filament\Resources\Itemmaingroups\Pages\ListItemmaingroups;
use App\Filament\Resources\Itemmaingroups\Schemas\ItemmaingroupForm;
use App\Filament\Resources\Itemmaingroups\Tables\ItemmaingroupsTable;
use App\Models\Itemmaingroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ItemmaingroupResource extends Resource
{
    protected static ?string $model = Itemmaingroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ItemmaingroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemmaingroupsTable::configure($table);
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
            'index' => ListItemmaingroups::route('/'),
            'create' => CreateItemmaingroup::route('/create'),
            'edit' => EditItemmaingroup::route('/{record}/edit'),
        ];
    }
}
