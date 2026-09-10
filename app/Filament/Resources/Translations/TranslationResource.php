<?php

namespace App\Filament\Resources\Translations;

use App\Filament\Resources\Translations\Pages\CreateTranslation;
use App\Filament\Resources\Translations\Pages\EditTranslation;
use App\Filament\Resources\Translations\Pages\ListTranslations;
use App\Filament\Resources\Translations\Pages\ViewTranslation;
use App\Filament\Resources\Translations\Schemas\TranslationForm;
use App\Filament\Resources\Translations\Tables\TranslationsTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\Translation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TranslationResource extends Resource
{
    protected static ?string $model = Translation::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup =
        'Rendszerbeállítások';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Fordítások';

    protected static ?string $modelLabel = 'fordítás';

    protected static ?string $pluralModelLabel = 'fordítások';

    protected static ?string $recordTitleAttribute = 'value';

    public static function form(Schema $schema): Schema
    {
        return TranslationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminResourceTable::configure(
            TranslationsTable::configure($table),
            Translation::class,
        );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranslations::route('/'),
            'create' => CreateTranslation::route('/create'),
            'view' => ViewTranslation::route('/{record}'),
            'edit' => EditTranslation::route('/{record}/edit'),
        ];
    }
}
