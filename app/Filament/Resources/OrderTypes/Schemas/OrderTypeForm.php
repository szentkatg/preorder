<?php

namespace App\Filament\Resources\OrderTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OrderTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true),

                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                Toggle::make('include_in_supplier_order')
                    ->label('Beszámít a szállítói rendelésbe')
                    ->default(false),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}
