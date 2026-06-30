<?php

namespace App\Filament\Resources\PriceListItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PriceListItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('price_list_id')
                    ->label('Árlista')
                    ->relationship('priceList', 'name_hu')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('season_id')
                    ->label('Szezon')
                    ->relationship('season', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->label('Termék')
                    ->relationship('product', 'model_code')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('net_price')
                    ->label('Ár')
                    ->numeric()
                    ->required(),
            ]);
    }
}