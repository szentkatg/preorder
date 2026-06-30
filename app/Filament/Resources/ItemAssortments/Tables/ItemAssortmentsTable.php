<?php

namespace App\Filament\Resources\ItemAssortments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemAssortmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.model_code')
                    ->label('Modell')
                    ->searchable(),

                TextColumn::make('color.name_hu')
                    ->label('Szín'),

                TextColumn::make('assortmentSku.sku_code')
                    ->label('Gyűjtő SKU')
                    ->searchable(),

                TextColumn::make('componentSku.sku_code')
                    ->label('Tartalmazott SKU')
                    ->searchable(),

                TextColumn::make('quantity')
                    ->label('Darab'),
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