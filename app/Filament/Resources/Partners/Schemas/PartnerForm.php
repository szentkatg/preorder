<?php

namespace App\Filament\Resources\Partners\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('erp_partner_code')
                    ->label('Partner kód')
                    ->required()
                    ->maxLength(255),

                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->maxLength(255),

                TextInput::make('phone')
                    ->label('Telefon')
                    ->maxLength(255),

                TextInput::make('tax_number')
                    ->label('Adószám')
                    ->maxLength(255),
            ]);
    }
}