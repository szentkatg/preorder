<?php

namespace App\Filament\Resources\Currencies\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required()
                    ->maxLength(3),

                TextInput::make('name')
                    ->label('Név')
                    ->required(),

                TextInput::make('symbol')
                    ->label('Jel')
                    ->maxLength(10),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}
