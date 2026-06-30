<?php

namespace App\Filament\Resources\PartnerUsers\Schemas;

use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PartnerUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('addresses', [])),

                Select::make('role')
                    ->label('Szerepkör')
                    ->options([
                        PartnerUser::ROLE_PARTNER_ADMIN => 'Partner admin - minden saját cím',
                        PartnerUser::ROLE_ADDRESS_USER => 'Cím felhasználó - kijelölt címek',
                        PartnerUser::ROLE_SALES_REP => 'Területi képviselő',
                    ])
                    ->default(PartnerUser::ROLE_PARTNER_ADMIN)
                    ->required()
                    ->live(),

                TextInput::make('name')
                    ->label('Név')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('password')
                    ->label('Jelszó')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),

                Toggle::make('active')
                    ->label('Aktív')
                    ->default(true)
                    ->required(),

                Select::make('addresses')
                    ->label('Engedélyezett címek')
                    ->relationship(
                        name: 'addresses',
                        titleAttribute: 'addrid',
                        modifyQueryUsing: fn ($query, $get) => $query
                            ->where('partner_id', $get('partner_id'))
                            ->orderBy('addrid')
                    )
                    ->getOptionLabelFromRecordUsing(function (PartnerAddress $record): string {
                        $fullAddress = trim(implode(' ', array_filter([
                            $record->country,
                            $record->zip,
                            $record->city,
                            $record->street,
                        ])));

                        return trim(implode(' - ', array_filter([
                            $record->addrid,
                            $record->name,
                            $fullAddress,
                        ])));
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        $address = PartnerAddress::find($value);

                        if (! $address) {
                            return null;
                        }

                        return trim(implode(' - ', array_filter([
                            $address->addrid,
                            $address->name,
                        ])));
                    })
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->disabled(fn ($get): bool => blank($get('partner_id')))
                    ->visible(fn ($get): bool => in_array($get('role'), [
                        PartnerUser::ROLE_ADDRESS_USER,
                        PartnerUser::ROLE_SALES_REP,
                    ], true))
                    ->helperText('Cím felhasználónál kötelező megadni, mely címekhez férhet hozzá.'),

                Select::make('partners')
                    ->label('Engedélyezett partnerek')
                    ->relationship(
                        name: 'partners',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->orderBy('name')
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get): bool => $get('role') === PartnerUser::ROLE_SALES_REP)
                    ->helperText('Területi képviselő ezeknek a partnereknek a rendeléseit láthatja.'),
            ]);
    }
}