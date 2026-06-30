<?php

namespace App\Filament\Resources\OrderSheetTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class OrderSheetTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kód')
                    ->required(),

                TextInput::make('name_hu')
                    ->label('Név HU')
                    ->required(),

                TextInput::make('name_en')
                    ->label('Név EN'),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}