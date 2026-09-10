<?php

namespace App\Filament\Resources\Catalogs;

use App\Filament\Resources\Catalogs\Pages\CreateCatalog;
use App\Filament\Resources\Catalogs\Pages\EditCatalog;
use App\Filament\Resources\Catalogs\Pages\ListCatalogs;
use App\Filament\Resources\Catalogs\Pages\ViewCatalog;
use App\Filament\Resources\Catalogs\Schemas\CatalogForm;
use App\Filament\Resources\Catalogs\Tables\CatalogsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\Catalog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CatalogResource extends Resource
{
    protected static ?string $model = Catalog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Törzsadatok';

    protected static ?string $navigationLabel = 'Katalógusok';

    protected static ?string $modelLabel = 'Katalógus';

    protected static ?string $pluralModelLabel = 'Katalógusok';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CatalogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            CatalogsTable::configure($table),
            Catalog::class,
        );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogs::route('/'),
            'create' => CreateCatalog::route('/create'),
            'view' => ViewCatalog::route('/{record}'),
            'edit' => EditCatalog::route('/{record}/edit'),
        ];
    }
}
