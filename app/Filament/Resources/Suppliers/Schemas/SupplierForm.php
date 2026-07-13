<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('erp_partner_code')
                    ->label('ERP partnerkód')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),

                TextInput::make('short_name')
                    ->label('Rövid név')
                    ->required()
                    ->maxLength(255),

                TextInput::make('name')
                    ->label('Teljes név')
                    ->required()
                    ->maxLength(255),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}