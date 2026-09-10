<?php

namespace App\Filament\Resources\PartnerAddresses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartnerAddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('partner.name')
                    ->label('Partner')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('addrid')
                    ->label('AddrID')
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Cím / bolt')
                    ->searchable(),

                TextColumn::make('city')
                    ->label('Város')
                    ->searchable(),

                TextColumn::make('priceList.code')
                    ->label('Árlista'),

                TextColumn::make('currency.code')
                    ->label('Pénznem'),

                TextColumn::make('language.code')
                    ->label('Nyelv'),

                IconColumn::make('allow_assortment_ordering')
                    ->label('GY')
                    ->boolean(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
