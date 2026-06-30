<?php

namespace App\Filament\Resources\Catalogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CatalogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('brand_id')
                    ->label('Márka')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('order_sheet_type_id')
                    ->label('Rendelőlap típus')
                    ->relationship('orderSheetType', 'name_hu')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('name')
                    ->label('Katalógus neve')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('pdf_file')
                    ->label('PDF fájl útvonala')
                    ->helperText('Példa: catalogs/2027SS_Dealer_TEXTIL_BOOK_FINAL.pdf')
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('image_folder')
                    ->label('Oldalképek mappája')
                    ->helperText('Példa: 2027ss-textil. A képek helye: public/catalog-pages/2027ss-textil/page-001.jpg')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('page_offset')
                    ->label('Oldal eltérés')
                    ->helperText('A katalógusban szereplő oldalszámhoz hozzáadott érték. Pl. -2 vagy 0.')
                    ->numeric()
                    ->default(0)
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true)
                    ->required(),
            ]);
    }
}
