<?php

namespace App\Filament\Resources\PriceListItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceListItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priceList.code')
                    ->label('Árlista')
                    ->searchable(),

                TextColumn::make('season.code')
                    ->label('Szezon')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.model_code')
                    ->label('Modell')
                    ->searchable(),

                TextColumn::make('product.name_hu')
                    ->label('Terméknév')
                    ->searchable(),

                TextColumn::make('net_price')
                    ->label('Ár')
                    ->numeric(decimalPlaces: 2),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}