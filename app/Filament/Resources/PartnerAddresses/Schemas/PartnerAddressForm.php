<?php

namespace App\Filament\Resources\PartnerAddresses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PartnerAddressForm
{
    public static function configure(Schema $schema, bool $showPartnerSelect = true): Schema
    {
        $components = [];

        if ($showPartnerSelect) {
            $components[] = Select::make('partner_id')
                ->label('Partner')
                ->relationship('partner', 'name')
                ->searchable()
                ->preload()
                ->required();
        }

        return $schema
            ->components([
                ...$components,

                TextInput::make('addrid')
                    ->label('Címkód'),

                TextInput::make('name')
                    ->label('Név')
                    ->required(),

                TextInput::make('country')
                    ->label('Ország'),

                TextInput::make('zip')
                    ->label('Irányítószám'),

                TextInput::make('city')
                    ->label('Város'),

                TextInput::make('street')
                    ->label('Utca'),

                TextInput::make('contact_name')
                    ->label('Kapcsolattartó'),

                TextInput::make('email')
                    ->email(),

                TextInput::make('phone')
                    ->tel(),

                Select::make('price_list_id')
                    ->label('Árlista')
                    ->relationship('priceList', 'code')
                    ->searchable()
                    ->preload(),

                Select::make('currency_id')
                    ->label('Pénznem')
                    ->relationship('currency', 'code')
                    ->searchable()
                    ->preload(),

                Select::make('language_id')
                    ->label('Nyelv')
                    ->relationship('language', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('brands')
                    ->label('Márkák')
                    ->relationship('brands', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                Select::make('orderSheetTypes')
                    ->label('Rendelőlap típusok')
                    ->relationship('orderSheetTypes', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),

                Toggle::make('allow_assortment_ordering')
                    ->label('Gyűjtős rendelés engedélyezett')
                    ->default(true),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true),
            ]);
    }
}
