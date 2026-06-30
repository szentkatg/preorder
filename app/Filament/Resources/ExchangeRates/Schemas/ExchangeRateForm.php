<?php

namespace App\Filament\Resources\ExchangeRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->relationship('season', 'name')
                    ->required(),
                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('rate_to_huf')
                    ->required()
                    ->numeric(),
                DatePicker::make('valid_from'),
                Toggle::make('active')
                    ->required(),
            ]);
    }
}
