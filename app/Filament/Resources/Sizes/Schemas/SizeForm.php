<?php

namespace App\Filament\Resources\Sizes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SizeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(20),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                Toggle::make('active')
                    ->default(true),
            ]);
    }
}