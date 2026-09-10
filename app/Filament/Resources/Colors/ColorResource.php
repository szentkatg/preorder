<?php

namespace App\Filament\Resources\Colors;

use App\Filament\Resources\Colors\Pages\CreateColor;
use App\Filament\Resources\Colors\Pages\EditColor;
use App\Filament\Resources\Colors\Pages\ListColors;
use App\Filament\Resources\Colors\Pages\ViewColor;
use App\Filament\Resources\Colors\Schemas\ColorForm;
use App\Filament\Resources\Colors\Tables\ColorsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\Color;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ColorResource extends Resource
{
    protected static ?string $model = Color::class;
    
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ColorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            ColorsTable::configure($table),
            Color::class,
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
            'index' => ListColors::route('/'),
            'create' => CreateColor::route('/create'),
            'view' => ViewColor::route('/{record}'),
            'edit' => EditColor::route('/{record}/edit'),
        ];
    }
}
