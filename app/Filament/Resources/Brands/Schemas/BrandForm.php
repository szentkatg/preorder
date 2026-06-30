<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required(),

                TextInput::make('name')
                    ->label('Név')
                    ->required(),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}