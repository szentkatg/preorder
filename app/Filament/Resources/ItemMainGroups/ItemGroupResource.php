<?php

namespace App\Filament\Resources\ItemMainGroups;

use App\Filament\Resources\ItemMainGroups\Pages\CreateItemGroup;
use App\Filament\Resources\ItemMainGroups\Pages\EditItemGroup;
use App\Filament\Resources\ItemMainGroups\Pages\ListItemGroups;
use App\Filament\Resources\ItemMainGroups\Pages\ViewItemGroup;
use App\Filament\Resources\ItemMainGroups\Schemas\ItemGroupForm;
use App\Filament\Resources\ItemMainGroups\Tables\ItemGroupsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\ItemMainGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ItemGroupResource extends Resource
{
    protected static ?string $model = ItemMainGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'item-main-groups';

    public static function form(Schema $schema): Schema
    {
        return ItemGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            ItemGroupsTable::configure($table),
            ItemMainGroup::class,
        );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemGroups::route('/'),
            'create' => CreateItemGroup::route('/create'),
            'view' => ViewItemGroup::route('/{record}'),
            'edit' => EditItemGroup::route('/{record}/edit'),
        ];
    }
}
