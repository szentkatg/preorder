<?php

namespace App\Filament\Resources\ColorImages;

use App\Filament\Resources\ColorImages\Pages\CreateColorImage;
use App\Filament\Resources\ColorImages\Pages\EditColorImage;
use App\Filament\Resources\ColorImages\Pages\ListColorImages;
use App\Filament\Resources\ColorImages\Pages\ViewColorImage;
use App\Filament\Resources\ColorImages\Schemas\ColorImageForm;
use App\Filament\Resources\ColorImages\Tables\ColorImagesTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\ColorImage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ColorImageResource extends Resource
{
    protected static ?string $model = ColorImage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return ColorImageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            ColorImagesTable::configure($table),
            ColorImage::class,
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
            'index' => ListColorImages::route('/'),
            'create' => CreateColorImage::route('/create'),
            'view' => ViewColorImage::route('/{record}'),
            'edit' => EditColorImage::route('/{record}/edit'),
        ];
    }
}
