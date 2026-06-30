<?php

namespace App\Filament\Resources\SizeRanges;

use App\Filament\Resources\SizeRanges\Pages\CreateSizeRange;
use App\Filament\Resources\SizeRanges\RelationManagers;
use App\Filament\Resources\SizeRanges\Pages\EditSizeRange;
use App\Filament\Resources\SizeRanges\Pages\ListSizeRanges;
use App\Filament\Resources\SizeRanges\Schemas\SizeRangeForm;
use App\Filament\Resources\SizeRanges\Tables\SizeRangesTable;
use App\Models\SizeRange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SizeRangeResource extends Resource
{
    protected static ?string $model = SizeRange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SizeRangeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SizeRangesTable::configure($table);
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
            'index' => ListSizeRanges::route('/'),
            'create' => CreateSizeRange::route('/create'),
            'edit' => EditSizeRange::route('/{record}/edit'),
        ];
    }
}
