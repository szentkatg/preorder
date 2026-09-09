<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Models\Partner;
use Filament\Forms\Components\Select;
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

                Select::make('sales_rep_erp_partner_code')
                    ->label('Területi képviselő')
                    ->relationship(
                        name: 'salesRepresentative',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->whereNotNull('erp_partner_code')
                            ->orderBy('name')
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Partner $record): string => "{$record->erp_partner_code} - {$record->name}"
                    )
                    ->searchable(['erp_partner_code', 'name'])
                    ->preload()
                    ->nullable(),

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
