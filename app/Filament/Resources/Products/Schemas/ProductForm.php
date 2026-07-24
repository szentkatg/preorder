<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Forms\Components\DatePicker;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->required(),

                Select::make('item_main_group_id')
                    ->label('ItemMainGroup')
                    ->relationship('itemMainGroup', 'name')
                    ->required(),

                Select::make('size_range_id')
                    ->label('Méretsor')
                    ->relationship('sizeRange', 'code')
                    ->required(),

                TextInput::make('model_code')
                    ->label('Modell kód')
                    ->required(),

                TextInput::make('name_hu')
                    ->label('Név HU')
                    ->required(),

                TextInput::make('name_en')
                    ->label('Név EN'),
                Select::make('brand_id')
                    ->label('Márka')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                
                Select::make('order_sheet_type_id')
                    ->label('Rendelőlap típus')
                    ->relationship('orderSheetType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('rounding_rule_id')
                    ->label('Gyártási kerekítés')
                    ->relationship('roundingRule', 'name')
                    ->searchable()
                    ->preload(),

                DatePicker::make('promised_delivery_date')
                    ->label('Ígért szállítási határidő')
                    ->native(false),

                TextInput::make('catalog_group_name_hu')
                    ->label('Katalógus csoport HU'),

                TextInput::make('catalog_group_name_en')
                    ->label('Katalógus csoport EN'),

                TextInput::make('catalog_group_sort')
                    ->label('Katalógus csoport sorrend')
                    ->numeric()
                    ->default(0),

                TextInput::make('catalog_sort')
                    ->label('Katalógus sorrend')
                    ->numeric(),
    
                TextInput::make('catalog_page')
                    ->label('Katalógus oldal')
                    ->numeric(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}
