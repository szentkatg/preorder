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

                TextInput::make('addrid')
                    ->label('Címkód')
                    ->maxLength(20),

                TextInput::make('country_code')
                    ->label('Országkód')
                    ->helperText('ISO 3166-1 alpha-2 kód, pl. CN, VN, TR.')
                    ->maxLength(2)
                    ->dehydrateStateUsing(
                        fn (?string $state): ?string =>
                            filled($state) ? mb_strtoupper(trim($state)) : null
                    ),

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
