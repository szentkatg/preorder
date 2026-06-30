<?php

namespace App\Filament\Resources\SizeRanges\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SizeRangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(50),

                TextInput::make('matrix_group')
                    ->label('Mátrix csoport')
                    ->maxLength(255),

                TextInput::make('name_hu')
                    ->label('Név HU')
                    ->required(),

                TextInput::make('name_en')
                    ->label('Név EN'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}